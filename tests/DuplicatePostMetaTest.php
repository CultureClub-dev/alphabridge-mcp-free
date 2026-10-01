<?php
/**
 * wp_duplicate_post copies a post with its meta, so a page built with a page
 * builder stays one:
 * - values come over byte for byte: serialized values are not serialized a
 *   second time, backslashes survive, inside objects too;
 * - Elementor elements get new ids, everything else in the layout stays;
 * - the original's editing state, builder caches, credential-like keys and
 *   protected keys of other plugins stay behind, each with its reason;
 * - a builder's keys, its page template included, go together or not at all;
 * - a key is stored under the name it was checked by, backslashes included;
 * - meta needs the right to edit the original, builder data in addition
 *   unfiltered_html; who lacks it gets the copy as before and is told why;
 * - the copy of a revision gets no meta, which WordPress would write to the
 *   live post.
 *
 * The Elementor and Beaver Builder values are the raw meta measured on
 * 30.09.2026 (tests/fixtures/builder-meta-2026-09-30.json).
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use AB_MCP_Tools_Content;
use AB_MCP_Tool_Registry;

final class DuplicatePostMetaTest extends TestCase {

	private const SRC = 40;

	protected function setUp(): void {
		ab_test_reset();
		ab_test_add_user( 7 );
		$GLOBALS['ab_test_current_user'] = 7;
		ab_test_add_post( self::SRC, array( 'post_type' => 'page' ) );
		self::rights();
	}

	/**
	 * The account's rights. edit_post_meta as WordPress answers it: never for
	 * a protected key, otherwise per key.
	 *
	 * @param bool     $edit_source May edit the original.
	 * @param bool     $unfiltered  Has unfiltered_html.
	 * @param string[] $closed      Open keys whose edit_post_meta says no.
	 */
	private static function rights( bool $edit_source = true, bool $unfiltered = true, array $closed = array() ): void {
		$GLOBALS['ab_test_can'] = static function ( string $cap, array $args ) use ( $edit_source, $unfiltered, $closed ): bool {
			switch ( $cap ) {
				case 'edit_post':
					return self::SRC !== (int) ( $args[0] ?? 0 ) || $edit_source;
				case 'unfiltered_html':
					return $unfiltered;
				case 'edit_post_meta':
					$key = (string) ( $args[1] ?? '' );
					return ! is_protected_meta( $key, 'post' ) && ! in_array( $key, $closed, true );
				default:
					return true; // read_post, edit_posts, assign_terms.
			}
		};
	}

	/**
	 * The original's meta, raw as the database holds it.
	 *
	 * @param array<string,string|string[]> $raw Key => value or values.
	 */
	private static function meta( array $raw ): void {
		foreach ( $raw as $key => $value ) {
			$GLOBALS['ab_test_meta'][ self::SRC ][ $key ] = is_array( $value ) ? array_values( $value ) : array( $value );
		}
	}

	/** @return array<string,mixed> */
	private static function fixture( string $part ): array {
		$all = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/builder-meta-2026-09-30.json' ), true );
		return $all[ $part ];
	}

	/** @return array<string,mixed> */
	private static function duplicate(): array {
		$out = AB_MCP_Tools_Content::duplicate_post( array( 'id' => self::SRC ) );
		self::assertIsArray( $out, is_wp_error( $out ) ? $out->get_error_message() : '' );
		self::assertTrue( $out['duplicated'] );
		return $out;
	}

	/** @return array<string,string[]> The copy's meta, raw. */
	private static function copied( array $out ): array {
		return $GLOBALS['ab_test_meta'][ $out['new_id'] ] ?? array();
	}

	/**
	 * Element ids of an Elementor layout, in the order of the stored JSON.
	 *
	 * @return string[]
	 */
	private static function elementIds( string $raw ): array {
		preg_match_all( '/"id":"([^"]*)","elType"/', $raw, $m );
		return $m[1];
	}

	/** @return string[] */
	private static function skipped( array $out, string $reason ): array {
		return $out['meta_skipped'][ $reason ]['keys'] ?? array();
	}

	/* ------------------------------------------------------------ byte for byte */

	public function testOpenMetaIsCopiedByteForByte(): void {
		self::meta(
			array(
				'subtitle'       => 'C:\\Docs "quoted"',
				'gallery'        => serialize( array( 5, 'x\\y' ) ),
				// A string that itself looks serialized is stored serialized
				// once more by WordPress; the copy must not add a third layer.
				'note'           => serialize( serialize( 'looks serialized' ) ),
				'json_field'     => '{"a":"b\\/c","u":"\\u00d6"}',
				'multi'          => array( 'one', 'two' ),
			)
		);
		$out = self::duplicate();

		self::assertSame( $GLOBALS['ab_test_meta'][ self::SRC ], self::copied( $out ) );
		self::assertSame( 5, $out['meta_copied']['count'] );
		self::assertSame( array( 'subtitle', 'gallery', 'note', 'json_field', 'multi' ), $out['meta_copied']['keys'] );
		self::assertArrayNotHasKey( 'meta_altered_on_write', $out );
		self::assertArrayNotHasKey( 'meta_skipped', $out );
	}

	public function testASerializedValueIsNotSerializedTwice(): void {
		$raw = serialize( array( 'title' => 'Alpine', 'list' => array( 1, 2 ) ) );
		self::meta( array( 'gallery' => $raw ) );
		$out = self::duplicate();
		self::assertSame( array( $raw ), self::copied( $out )['gallery'], 'handed to add_post_meta() as a string, the value would come back as s:…:"a:2:{…}";' );
	}

	public function testBackslashesInsideObjectsSurvive(): void {
		// Beaver Builder keeps its nodes as stdClass objects keyed by node id
		// (measured: beaver_hat_stdclass, node "j5rfme4zbgs2"); a backslash in
		// a setting is the case wp_slash() does not reach.
		$raw = serialize(
			array(
				'j5rfme4zbgs2' => (object) array(
					'node'     => 'j5rfme4zbgs2',
					'type'     => 'row',
					'settings' => (object) array( 'text' => 'C:\\Docs', 'css' => '.a::before{content:"\\201C"}' ),
				),
			)
		);
		self::meta(
			array(
				'_fl_builder_data'    => $raw,
				'_fl_builder_enabled' => '1',
			)
		);
		$out = self::duplicate();
		self::assertSame( array( $raw ), self::copied( $out )['_fl_builder_data'] );
		self::assertArrayNotHasKey( 'meta_altered_on_write', $out );
	}

	public function testTheMeasuredBeaverBuilderMetaIsCopiedUnchanged(): void {
		$beaver = self::fixture( 'beaver' );
		self::meta( $beaver );
		$out = self::duplicate();

		foreach ( $beaver as $key => $raw ) {
			self::assertSame( array( $raw ), self::copied( $out )[ $key ] ?? null, $key );
		}
		self::assertSame( 'beaver-builder', $out['built_with'] );
		self::assertStringContainsString( 'ids inside it were not renewed', implode( ' ', $out['notes'] ) );
	}

	/* ---------------------------------------------------------------- Elementor */

	public function testAnElementorPageIsCopiedWithNewElementIds(): void {
		$v3 = self::fixture( 'elementor_v3' );
		// The first measurement saved without the editor's marker; a page
		// opened in Elementor carries it. _elementor_element_cache is the
		// element cache Elementor keeps for 24 hours (document.php:1957).
		// _elementor_controls_usage is what Elementor's usage module counts
		// for the page; the value here is a placeholder, not measured.
		self::meta( $v3 + array( '_elementor_edit_mode' => 'builder', '_elementor_element_cache' => 'a:0:{}', '_elementor_controls_usage' => 'a:0:{}' ) );
		$out  = self::duplicate();
		$copy = self::copied( $out );

		$old = self::elementIds( $v3['_elementor_data'] );
		$new = self::elementIds( $copy['_elementor_data'][0] );
		self::assertSame( array( 'c0ffee1', 'a1b2c3d', 'b2c3d4e', 'c3d4e5f' ), $old );
		self::assertCount( 4, $new );
		self::assertSame( 4, $out['elementor_ids_renewed'] );
		foreach ( $new as $id ) {
			self::assertMatchesRegularExpression( '/^[0-9a-f]{7}$/', $id, 'the form of the Elementor editor' );
		}
		self::assertSame( $new, array_values( array_unique( $new ) ) );
		self::assertSame( array(), array_values( array_intersect( $new, $old ) ) );

		// Everything but the ids is the stored layout, byte for byte.
		$back = $copy['_elementor_data'][0];
		foreach ( $new as $i => $id ) {
			$back = str_replace( '"id":"' . $id . '"', '"id":"' . $old[ $i ] . '"', $back );
		}
		self::assertSame( $v3['_elementor_data'], $back );

		foreach ( array( '_elementor_template_type', '_elementor_version', '_elementor_edit_mode', '_wp_page_template' ) as $key ) {
			self::assertSame( $GLOBALS['ab_test_meta'][ self::SRC ][ $key ], $copy[ $key ] ?? null, $key );
		}
		$caches = array( '_elementor_css', '_elementor_global_class_usage_indexed', '_elementor_global_class_usage_indexed_preview', '_elementor_page_assets', '_elementor_element_cache', '_elementor_controls_usage' );
		self::assertEqualsCanonicalizing( $caches, self::skipped( $out, 'cache' ) );
		foreach ( $caches as $key ) {
			self::assertArrayNotHasKey( $key, $copy, $key );
		}
		self::assertSame( 'elementor', $out['built_with'] );
		self::assertSame( 5, $out['meta_copied']['count'] );
		// Read back against what was written, the renewed layout, not the
		// original's: otherwise every Elementor copy would be reported here.
		self::assertArrayNotHasKey( 'meta_altered_on_write', $out );
	}

	public function testEachElementPassesThroughElementorsOwnFilter(): void {
		// Elementor's atomic widgets renew their style ids on this filter
		// (atomic-import-export.php); the copy keeps what it returns.
		$seen = array();
		add_filter(
			'elementor/document/element/replace_id',
			static function ( $element ) use ( &$seen ) {
				$seen[]                = $element['id'];
				$element['by_filter'] = true;
				return $element;
			}
		);
		$v4 = self::fixture( 'elementor_v4' );
		self::meta( $v4 );
		$out = self::duplicate();

		$stored = json_decode( self::copied( $out )['_elementor_data'][0], true );
		self::assertEqualsCanonicalizing( self::elementIds( self::copied( $out )['_elementor_data'][0] ), $seen );
		self::assertCount( 4, $seen );
		self::assertTrue( $stored[0]['by_filter'] );
		self::assertTrue( $stored[0]['elements'][2]['by_filter'] );
		self::assertSame( 'e-button', $stored[0]['elements'][2]['widgetType'] );
		self::assertSame( 'elementor', $out['built_with'] );
		self::assertArrayNotHasKey( 'meta_altered_on_write', $out );
	}

	public function testANewIdNeverRepeatsAnIdOfTheOriginal(): void {
		// The first two draws are ids the layout already uses.
		$GLOBALS['ab_test_rand'] = array( 0xc0ffee1, 0xa1b2c3d, 1, 2, 3, 4 );
		self::meta( array( '_elementor_data' => self::fixture( 'elementor_v3' )['_elementor_data'] ) );
		$out = self::duplicate();
		self::assertEqualsCanonicalizing(
			array( '0000001', '0000002', '0000003', '0000004' ),
			self::elementIds( self::copied( $out )['_elementor_data'][0] )
		);
	}

	/* ------------------------------------------------------------------ SeedProd */

	/**
	 * SeedProd keeps the JSON its editor opens in post_content_filtered, not
	 * in meta (measured 30.09.2026); its meta only marks the page.
	 */
	private static function seedprodPage(): void {
		$GLOBALS['ab_test_posts'][ self::SRC ]->post_content_filtered = '{"document":{"sections":[{"type":"section"}]},"note":"C:\\\\Docs"}';
		self::meta(
			array(
				'_seedprod_page'               => '1',
				'_seedprod_page_template_type' => 'lp',
			)
		);
	}

	public function testASeedProdPageComesWithTheJsonItsEditorOpens(): void {
		self::seedprodPage();
		$out = self::duplicate();
		self::assertSame( $GLOBALS['ab_test_posts'][ self::SRC ]->post_content_filtered, $GLOBALS['ab_test_inserted'][0]['post_content_filtered'] );
		self::assertSame( array( '_seedprod_page', '_seedprod_page_template_type' ), $out['meta_copied']['keys'] );
		self::assertSame( 'seedprod', $out['built_with'] );
	}

	public function testWithoutUnfilteredHtmlTheSeedProdJsonStaysBehindWithItsMeta(): void {
		// The flags without the JSON would open an empty editor on the copy.
		self::rights( true, false );
		self::seedprodPage();
		$out = self::duplicate();
		self::assertSame( '', $GLOBALS['ab_test_inserted'][0]['post_content_filtered'] );
		self::assertEqualsCanonicalizing( array( '_seedprod_page', '_seedprod_page_template_type' ), self::skipped( $out, 'needs_unfiltered_html' ) );
		self::assertStringContainsString( 'post_content_filtered', implode( ' ', $out['notes'] ) );
	}

	public function testAReaderGetsNoSeedProdJsonEither(): void {
		self::rights( false, true );
		self::seedprodPage();
		$out = self::duplicate();
		self::assertSame( '', $GLOBALS['ab_test_inserted'][0]['post_content_filtered'] );
		self::assertStringContainsString( 'post_content_filtered', implode( ' ', $out['notes'] ) );
	}

	public function testElementorDataThatIsNotJsonIsCopiedAsItIs(): void {
		self::meta( array( '_elementor_data' => 'not json' ) );
		$out = self::duplicate();
		self::assertSame( array( 'not json' ), self::copied( $out )['_elementor_data'] );
		self::assertArrayNotHasKey( 'elementor_ids_renewed', $out );
		self::assertStringContainsString( 'not valid JSON', implode( ' ', $out['notes'] ) );
	}

	/* -------------------------------------------------------------- left behind */

	public function testTheOriginalsEditingStateStaysBehind(): void {
		$state = array(
			'_edit_lock'            => '1790778507:7',
			'_edit_last'            => '7',
			'_wp_old_slug'          => 'old-address',
			'_wp_old_date'          => '2026-09-01',
			'_wp_trash_meta_status' => 'publish',
			'_wp_trash_meta_time'   => '1790778507',
			'_wp_desired_post_slug' => 'wanted',
			'_encloseme'            => '1',
			'_pingme'               => '1',
		);
		self::meta( $state + array( 'colour' => 'blue' ) );
		$out = self::duplicate();

		self::assertSame( array( 'colour' => array( 'blue' ) ), self::copied( $out ) );
		self::assertEqualsCanonicalizing( array_keys( $state ), self::skipped( $out, 'original_only' ) );
		self::assertNotSame( '', $out['meta_skipped']['original_only']['why'] );
	}

	public function testCredentialKeysAreNeverCopied(): void {
		// Not even where a builder prefix or the site's filter would let a key through.
		add_filter( 'ab_mcp_duplicate_copy_protected_meta', '__return_true' );
		self::meta(
			array(
				'api_key'              => 'value',
				'_shop_secret'         => 'value',
				'_elementor_api_token' => 'value',
				'colour'               => 'blue',
			)
		);
		$out = self::duplicate();
		self::assertSame( array( 'colour' => array( 'blue' ) ), self::copied( $out ) );
		self::assertEqualsCanonicalizing( array( 'api_key', '_shop_secret', '_elementor_api_token' ), self::skipped( $out, 'sensitive' ) );
	}

	public function testProtectedKeysOfOtherPluginsStayUnlessTheSiteAllowsThem(): void {
		self::meta(
			array(
				'_yoast_wpseo_title' => 'SEO title',
				'_thumbnail_id'      => '12',
				'_wp_page_template'  => 'templates/full-width.php',
			)
		);
		$out = self::duplicate();
		self::assertSame( array( '_yoast_wpseo_title' ), self::skipped( $out, 'protected' ) );
		self::assertStringContainsString( 'ab_mcp_duplicate_copy_protected_meta', $out['meta_skipped']['protected']['why'] );
		self::assertSame( array( '_thumbnail_id', '_wp_page_template' ), $out['meta_copied']['keys'], 'featured image and page template are WordPress\' own' );

		ab_test_reset();
		ab_test_add_user( 7 );
		$GLOBALS['ab_test_current_user'] = 7;
		ab_test_add_post( self::SRC, array( 'post_type' => 'page' ) );
		self::rights();
		self::meta( array( '_yoast_wpseo_title' => 'SEO title' ) );
		add_filter(
			'ab_mcp_duplicate_copy_protected_meta',
			static function ( $copy, $key ) {
				return '_yoast_wpseo_title' === $key;
			},
			10,
			2
		);
		$out = self::duplicate();
		self::assertSame( array( 'SEO title' ), self::copied( $out )['_yoast_wpseo_title'] ?? null );
	}

	public function testAnOpenKeyClosedToTheAccountStays(): void {
		self::rights( true, true, array( 'private_note' ) );
		self::meta( array( 'private_note' => 'x', 'colour' => 'blue' ) );
		$out = self::duplicate();
		self::assertSame( array( 'private_note' ), self::skipped( $out, 'not_allowed' ) );
		self::assertArrayNotHasKey( 'private_note', self::copied( $out ) );
	}

	public function testAnOpenBuilderKeyFollowsItsOwnEditRightToo(): void {
		// unfiltered_html is needed in addition to the key's own rule, not instead.
		self::rights( true, true, array( 'panels_data' ) );
		self::meta( array( 'panels_data' => serialize( array( 'widgets' => array() ) ) ) );
		$out = self::duplicate();
		self::assertSame( array( 'panels_data' ), self::skipped( $out, 'not_allowed' ) );
		self::assertSame( 'siteorigin', $out['built_with'] );
	}

	public function testAValueWordPressCannotStoreIsNamedNotLost(): void {
		// An object of a class that is not loaded: core's own unslashing in
		// add_metadata() could not handle it either.
		self::meta(
			array(
				'odd'    => 'O:18:"Some_Missing_Class":1:{s:1:"a";s:1:"b";}',
				'colour' => 'blue',
			)
		);
		$out = self::duplicate();
		self::assertSame( array( 'odd' ), self::skipped( $out, 'not_storable' ) );
		self::assertSame( array( 'colour' => array( 'blue' ) ), self::copied( $out ) );
	}

	public function testTheHookedBlocksTheEditorRemovedStayRemoved(): void {
		// WordPress writes this key itself (blocks.php, update_ignored_hooked_blocks_postmeta)
		// and reads it when it renders the content; as JSON of block names,
		// with the slashes json_encode() escapes.
		self::rights( true, false );
		self::meta( array( '_wp_ignored_hooked_blocks' => '["core\\/loginout"]' ) );
		$out = self::duplicate();
		self::assertSame( array( '["core\\/loginout"]' ), self::copied( $out )['_wp_ignored_hooked_blocks'] ?? null );
		self::assertSame( array( '_wp_ignored_hooked_blocks' ), $out['meta_copied']['keys'] );
		self::assertArrayNotHasKey( 'meta_skipped', $out );
	}

	/* ------------------------------------------------------ backslash in a key */

	public function testAKeyIsStoredUnderTheNameItWasCheckedBy(): void {
		// add_metadata() unslashes the key too. Handed over unslashed, these
		// open keys would land as _elementor_data, _elementor_edit_mode and
		// _edit_lock: builder data past the unfiltered_html rule, and the
		// original's lock on the copy.
		self::rights( true, false );
		$raw = array(
			'\\_elementor_data'      => '[{"id":"c0ffee1","elType":"widget","settings":{"html":"<script>x()<\\/script>"},"elements":[],"widgetType":"html"}]',
			'\\_elementor_edit_mode' => 'builder',
			'\\_edit_lock'           => '1790778507:7',
		);
		self::meta( $raw );
		$out  = self::duplicate();
		$copy = self::copied( $out );
		foreach ( array( '_elementor_data', '_elementor_edit_mode', '_edit_lock' ) as $key ) {
			self::assertArrayNotHasKey( $key, $copy, $key );
		}
		foreach ( $raw as $key => $value ) {
			self::assertSame( array( $value ), $copy[ $key ] ?? null, $key );
		}
		self::assertArrayNotHasKey( 'meta_altered_on_write', $out );
	}

	public function testReplacingWhatTheNewPostHasHitsTheSameKey(): void {
		$GLOBALS['ab_test_meta'][1001]['a\\b'] = array( 'default' );
		$GLOBALS['ab_test_meta'][1001]['ab']   = array( 'of another plugin' );
		self::meta( array( 'a\\b' => 'x' ) );
		$out = self::duplicate();
		self::assertSame( array( 'x' ), self::copied( $out )['a\\b'] ?? null );
		self::assertSame( array( 'of another plugin' ), self::copied( $out )['ab'] ?? null );
		self::assertArrayNotHasKey( 'meta_altered_on_write', $out );
	}

	/* ------------------------------------------------------- a builder as a set */

	public function testAnElementorPageTemplateStaysBehindWithTheLayout(): void {
		// Elementor applies elementor_canvas to any page; without the layout
		// the copy would show the text fallback on an empty canvas.
		self::rights( true, false );
		self::meta( array_merge( self::fixture( 'elementor_v3' ), array( '_elementor_edit_mode' => 'builder', '_wp_page_template' => 'elementor_canvas' ) ) );
		$out = self::duplicate();
		self::assertArrayNotHasKey( '_wp_page_template', self::copied( $out ) );
		self::assertContains( '_wp_page_template', self::skipped( $out, 'needs_unfiltered_html' ) );
	}

	public function testAnElementorTemplateOnAPageWithoutElementorDataIsCopied(): void {
		// Nothing of Elementor stays behind, so the page looks as before.
		self::rights( true, false );
		self::meta( array( '_wp_page_template' => 'elementor_canvas', 'subtitle' => 'Hello' ) );
		$out = self::duplicate();
		self::assertSame( array( 'elementor_canvas' ), self::copied( $out )['_wp_page_template'] ?? null );
		self::assertArrayNotHasKey( 'meta_skipped', $out );
	}

	public function testABuilderStaysBehindWholeWhenOneOfItsKeysMayNotBeCopied(): void {
		// Brizy's flag without its data would mark the copy as a Brizy page
		// with no layout.
		self::rights( true, true, array( 'brizy' ) );
		self::meta( array( 'brizy' => 'a:0:{}', 'brizy_enabled' => '1', 'colour' => 'blue' ) );
		$out = self::duplicate();
		self::assertSame( array( 'colour' => array( 'blue' ) ), self::copied( $out ) );
		self::assertSame( array( 'brizy' ), self::skipped( $out, 'not_allowed' ) );
		self::assertSame( array( 'brizy_enabled' ), self::skipped( $out, 'builder_incomplete' ) );
		self::assertStringContainsString( 'together or not at all', $out['meta_skipped']['builder_incomplete']['why'] );
		self::assertSame( 'brizy', $out['built_with'] );
	}

	public function testABuilderStaysBehindWholeWhenOneOfItsValuesCannotBeStored(): void {
		self::meta(
			array_merge(
				self::fixture( 'elementor_v3' ),
				array(
					'_elementor_edit_mode'     => 'builder',
					'_elementor_page_settings' => 'O:18:"Some_Missing_Class":1:{s:1:"a";s:1:"b";}',
					'_wp_page_template'        => 'elementor_header_footer',
					'subtitle'                 => 'Hello',
				)
			)
		);
		$out  = self::duplicate();
		$copy = self::copied( $out );
		self::assertSame( array( 'subtitle' => array( 'Hello' ) ), $copy );
		self::assertSame( array( '_elementor_page_settings' ), self::skipped( $out, 'not_storable' ) );
		self::assertEqualsCanonicalizing(
			array( '_elementor_data', '_elementor_template_type', '_elementor_version', '_elementor_edit_mode', '_wp_page_template' ),
			self::skipped( $out, 'builder_incomplete' )
		);
		self::assertArrayNotHasKey( 'elementor_ids_renewed', $out, 'no ids renewed in a layout that was not copied' );
	}

	/* ------------------------------------------------------------------ revisions */

	public function testARevisionIsCopiedWithoutWritingIntoThePostItBelongsTo(): void {
		// add_post_meta() and delete_post_meta() write to the post a revision
		// belongs to, and the copy of a revision is a revision.
		ab_test_add_post( 41, array( 'post_type' => 'page' ) );
		ab_test_add_post( self::SRC, array( 'post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => 41 ) );
		$GLOBALS['ab_test_meta'][41] = array( 'subtitle' => array( 'live' ) );
		self::meta( self::fixture( 'elementor_v4' ) + array( 'subtitle' => 'Revision', '_thumbnail_id' => '12' ) );
		$live = $GLOBALS['ab_test_meta'][41];

		$out = self::duplicate();
		self::assertSame( $live, $GLOBALS['ab_test_meta'][41], 'the live post is untouched' );
		self::assertSame( array(), $GLOBALS['ab_test_meta_added'] );
		self::assertSame( array(), $GLOBALS['ab_test_meta_deleted'] );
		self::assertSame( array( 'count' => 0, 'keys' => array() ), $out['meta_copied'] );
		self::assertStringContainsString( 'duplicate the post it belongs to (id 41)', implode( ' ', $out['notes'] ), 'the way to a copy with meta' );
		self::assertSame( 'revision', $GLOBALS['ab_test_inserted'][0]['post_type'], 'the row itself is copied as before' );
	}

	/* ------------------------------------------------------------------- rights */

	public function testBuilderDataNeedsUnfilteredHtml(): void {
		self::rights( true, false );
		$v3 = self::fixture( 'elementor_v3' );
		self::meta(
			$v3 + array(
				'_elementor_edit_mode' => 'builder',
				'panels_data'          => serialize( array( 'widgets' => array() ) ),
				'subtitle'             => 'Hello',
				'_thumbnail_id'        => '12',
			)
		);
		$out  = self::duplicate();
		$copy = self::copied( $out );

		foreach ( array_keys( $copy ) as $key ) {
			self::assertStringStartsNotWith( '_elementor_', $key );
		}
		self::assertArrayNotHasKey( 'panels_data', $copy, 'SiteOrigin keeps its layout in an open key' );
		self::assertEqualsCanonicalizing(
			array( '_elementor_data', '_elementor_template_type', '_elementor_version', '_elementor_edit_mode', 'panels_data' ),
			self::skipped( $out, 'needs_unfiltered_html' )
		);
		self::assertStringContainsString( 'run wp_duplicate_post with such an account', $out['meta_skipped']['needs_unfiltered_html']['why'] );
		self::assertEqualsCanonicalizing( array( '_wp_page_template', 'subtitle', '_thumbnail_id' ), $out['meta_copied']['keys'] );
		self::assertSame( 'elementor', $out['built_with'], 'the reason the copy is a plain page' );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testWhereNoAccountMayHaveUnfilteredHtmlTheWayIsNotSuchAnAccount(): void {
		// DISALLOW_UNFILTERED_HTML takes the capability from administrators too.
		define( 'DISALLOW_UNFILTERED_HTML', true );
		self::rights( true, false );
		self::seedprodPage();
		$out = self::duplicate();
		$why = $out['meta_skipped']['needs_unfiltered_html']['why'];
		self::assertStringContainsString( 'DISALLOW_UNFILTERED_HTML', $why );
		self::assertStringNotContainsString( 'with such an account', $why );
		$notes = implode( ' ', $out['notes'] );
		self::assertStringContainsString( 'DISALLOW_UNFILTERED_HTML', $notes, 'the note on post_content_filtered too' );
		self::assertStringNotContainsString( 'with such an account', $notes );
	}

	public function testAReaderGetsTheCopyAsBeforeWithoutMeta(): void {
		self::rights( false, true );
		self::meta( self::fixture( 'elementor_v3' ) + array( 'subtitle' => 'Hello' ) );
		$out = self::duplicate();

		self::assertSame( array(), self::copied( $out ) );
		self::assertSame( array(), $GLOBALS['ab_test_meta_added'] );
		self::assertSame( array( 'count' => 0, 'keys' => array() ), $out['meta_copied'] );
		self::assertArrayNotHasKey( 'meta_skipped', $out, 'keys of a post the account may not edit are not named' );
		self::assertStringContainsString( 'may read the original but not edit it', $out['notes'][0] );
		self::assertCount( 1, $GLOBALS['ab_test_inserted'], 'the copy itself is made, as before' );
	}

	/* -------------------------------------------------------------- the copy */

	public function testWhatTheNewPostAlreadyHasIsReplaced(): void {
		// A plugin that gives every new post a default on insert.
		$GLOBALS['ab_test_meta'][1001]['subtitle'] = array( 'default' );
		self::meta( array( 'subtitle' => 'Original' ) );
		$out = self::duplicate();
		self::assertSame( 1001, $out['new_id'] );
		self::assertSame( array( 'Original' ), self::copied( $out )['subtitle'] );
	}

	public function testAValueStoredDifferentlyIsReported(): void {
		add_filter(
			'sanitize_post_meta_subtitle',
			static function ( $value ) {
				return strtoupper( (string) $value );
			}
		);
		self::meta( array( 'subtitle' => 'Hello', 'colour' => 'blue' ) );
		$out = self::duplicate();
		self::assertSame( array( 'subtitle' ), $out['meta_altered_on_write'] );
		self::assertStringContainsString( 'meta_altered_on_write', implode( ' ', $out['notes'] ) );
	}

	public function testThePostWithoutMetaIsCopiedAsBefore(): void {
		$out = self::duplicate();
		self::assertSame( array( 'count' => 0, 'keys' => array() ), $out['meta_copied'] );
		self::assertArrayNotHasKey( 'built_with', $out );
		self::assertArrayNotHasKey( 'notes', $out );
		self::assertSame( 'Post 40 (Copy)', $GLOBALS['ab_test_inserted'][0]['post_title'] );
	}

	/* -------------------------------------------------------------- the tool */

	public function testTheDescriptionSaysWhatIsCopiedAndWhatIsNot(): void {
		$r = new AB_MCP_Tool_Registry();
		AB_MCP_Tools_Content::register( $r );
		$text = (string) $r->get( 'wp_duplicate_post' )['description'];
		foreach ( array( 'custom fields', 'page builder', 'Elementor element ids are renewed', 'unfiltered_html', 'may edit the original', 'credential' ) as $part ) {
			self::assertStringContainsString( $part, $text );
		}
		self::assertStringNotContainsString( 'Pro', $text, 'the free plugin\'s tools do not advertise' );
	}
}
