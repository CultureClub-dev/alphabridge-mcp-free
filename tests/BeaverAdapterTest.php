<?php
/**
 * Beaver Builder reader, against a page measured at Beaver Builder Lite
 * 2.11.0.6 (tests/fixtures/builders/beaver/): recognition and storage, the
 * outline in render order with Beaver's node ids, the measured fields only,
 * locks with their reasons, no code or styling in the answer, the draft, the
 * hash and the guard on wp_update_post.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Block_Reader;
use AB_MCP_Builder_Adapter_Beaver;
use AB_MCP_Builders;
use AB_MCP_Tools_Builders;
use PHPUnit\Framework\TestCase;

final class BeaverAdapterTest extends TestCase {

	use BuilderTestHelpers;
	use MetaBuilderFixtures;

	/** @var array */
	private $fx;

	protected function setUp(): void {
		ab_test_reset();
		ab_test_add_user( 7 );
		$GLOBALS['ab_test_current_user'] = 7;
		$this->mayDoAnything();
		$this->builders( array( 'beaver' ) );
		$this->fx = $this->fixture( 'page', 'beaver' );
	}

	private function outline( object $post, array $o = array() ): array {
		return ( new AB_MCP_Builder_Adapter_Beaver() )->outline( $post, $o );
	}

	/** The fixture's layout as loaded, for tests that change one node. */
	private function layout(): array {
		return $this->asLoaded( array( 'x' => $this->fx['meta']['_fl_builder_data'] ) )['x'];
	}

	/** A page whose published layout is $layout (draft and settings as measured). */
	private function pageWith( int $id, array $layout ): object {
		$meta                     = $this->asLoaded( $this->fx['meta'] );
		$meta['_fl_builder_data'] = $layout;
		return $this->fixturePage( $id, $this->fx, $meta );
	}

	/** The node of the layout whose module type is $type (the first). */
	private function module( array $layout, string $type ): object {
		foreach ( $layout as $node ) {
			if ( 'module' === $node->type && ( $node->settings->type ?? '' ) === $type ) {
				return $node;
			}
		}
		self::fail( 'No ' . $type . ' module in the fixture.' );
	}

	public function testTheFixtureHasTheFormatOfThePlansMeasurement(): void {
		self::assertTrue( $this->fx['measured'] );
		self::assertSame( '2.11.0.6', $this->fx['versions']['beaver'] );
		$start = $this->fixture( 'measured-start', 'beaver' );
		// Node ids are random per run; everything else of the first row is the same bytes.
		$norm = static function ( string $raw ): string {
			$raw = (string) preg_replace( '~^a:\d+:\{s:12:"[a-z0-9]{12}";~', '', $raw );
			return (string) preg_replace( '~s:12:"[a-z0-9]{12}"~', 'ID', $raw );
		};
		$old = $norm( $start['content'] );
		self::assertGreaterThan( 1500, strlen( $old ) );
		self::assertSame( $old, substr( $norm( $this->fx['meta']['_fl_builder_data'] ), 0, strlen( $old ) ) );
		foreach ( array( '_fl_builder_data', '_fl_builder_draft', '_fl_builder_data_settings', '_fl_builder_draft_settings' ) as $key ) {
			$raw = $this->fx['meta'][ $key ];
			self::assertSame( $raw, serialize( unserialize( $raw, array( 'allowed_classes' => array( 'stdClass' ) ) ) ), $key . ' survives the round trip, so raw() gives the stored bytes.' ); // phpcs:ignore
		}
		self::assertStringContainsString( 'O:8:"stdClass"', $this->fx['meta']['_fl_builder_data'], 'Beaver stores objects.' );
	}

	public function testRecognisedReadAndStoredInMeta(): void {
		$post = $this->fixturePage( 100, $this->fx );
		self::assertInstanceOf( AB_MCP_Builder_Adapter_Beaver::class, AB_MCP_Builders::for_post( $post ) );
		$built = AB_MCP_Builders::built_with( $post );
		self::assertSame( 'beaver', $built['builder'] );
		self::assertSame( 'B', $built['storage'] );
		self::assertSame( 'read', $built['support'] );
		self::assertSame( array(), $built['write_via'] );
		self::assertSame(
			array(
				array( 'loc' => 'meta:_fl_builder_data', 'shown' => true, 'role' => 'source' ),
				array( 'loc' => 'meta:_fl_builder_draft', 'shown' => false, 'role' => 'draft' ),
				array( 'loc' => 'post_content', 'shown' => false, 'role' => 'copy' ),
			),
			AB_MCP_Builders::copies( $post )
		);
		$raw = AB_MCP_Builders::raw( $post );
		self::assertSame( array( 'meta:_fl_builder_data', 'meta:_fl_builder_draft', 'post_content' ), array_keys( $raw ) );
		self::assertSame( $this->fx['meta']['_fl_builder_data'], $raw['meta:_fl_builder_data'], 'The stored bytes.' );
		self::assertSame( $this->fx['meta']['_fl_builder_draft'], $raw['meta:_fl_builder_draft'] );
	}

	public function testThePageCssAndJsKeysAreNeverRead(): void {
		$post    = $this->fixturePage( 101, $this->fx );
		$adapter = new AB_MCP_Builder_Adapter_Beaver();
		self::assertSame( array( '_fl_builder_data_settings', '_fl_builder_draft_settings' ), $adapter->locked_meta_keys() );
		self::assertStringContainsString( 'mzSeitenJs', $this->fx['meta']['_fl_builder_draft_settings'], 'The draft settings hold the page JS too (measured).' );
		$answer = AB_MCP_Tools_Builders::get_builder_layout( array( 'id' => 101 ) );
		foreach ( array( 'mzSeitenJs', 'mz-seiten-css', '_fl_builder_data_settings', '_fl_builder_draft_settings' ) as $needle ) {
			self::assertStringNotContainsString( $needle, $this->flat( $answer ) );
			self::assertStringNotContainsString( $needle, $this->flat( AB_MCP_Builders::raw( $post ) ) );
		}
	}

	public function testTheOutlineFollowsBeaversTreeAndOrder(): void {
		$els = $this->outline( $this->fixturePage( 102, $this->fx ) );
		self::assertSame(
			array( 'row', 'column-group', 'column', 'heading', 'rich-text', 'button', 'photo', 'html', 'widget', 'box', 'heading', 'row', 'column-group', 'column', 'callout', 'cta', 'icon', 'numbers', 'star-rating', 'button-group', 'video', 'audio', 'menu', 'sidebar', 'reusable-block' ),
			array_column( $els, 'type' )
		);
		self::assertSame( array( 0, 1, 2, 3, 3, 3, 3, 3, 3, 3, 4, 0, 1, 2, 3, 3, 3, 3, 3, 3, 3, 3, 3, 3, 3 ), array_column( $els, 'depth' ) );
		$layout = $this->layout();
		foreach ( $els as $el ) {
			self::assertArrayHasKey( $el['id'], $layout, 'Element ids are Beaver node ids.' );
			$node = $layout[ $el['id'] ];
			self::assertSame( '' === (string) $node->parent ? null : $node->parent, $el['parent'] );
		}
		$box = $this->module( $layout, 'box' );
		self::assertSame( $box->node, $els[10]['parent'], 'A module inside a box is its child.' );
	}

	public function testTheMeasuredFieldsAndOnlyThose(): void {
		$els = $this->byId( $this->outline( $this->fixturePage( 103, $this->fx ) ) );
		$by  = array();
		foreach ( $els as $el ) {
			$by[ $el['type'] ][] = $el;
		}
		self::assertSame( array( 'heading' => 'Willkommen bei Alpine Bikes', 'link' => 'https://example.com/ueber-uns' ), $this->values( $by['heading'][0] ) );
		self::assertSame( 'heading', $by['heading'][0]['fields']['heading']['kind'] );
		self::assertSame( array( 'text' => 'Öffnungszeiten: Mo–Fr 9–18 Uhr' ), $this->values( $by['rich-text'][0] ) );
		self::assertSame( 'html', $by['rich-text'][0]['fields']['text']['kind'] );
		self::assertSame( array( 'text' => 'Jetzt reservieren', 'link' => 'https://example.com/reservieren' ), $this->values( $by['button'][0] ) );
		self::assertSame( array( 'photo' => $this->fx['image']['id'], 'caption' => 'Bildunterschrift Alpine', 'link_url' => 'https://example.com/galerie' ), $this->values( $by['photo'][0] ) );
		self::assertSame( array( 'heading' => 'Ueberschrift in der Box' ), $this->values( $by['heading'][1] ) );
		self::assertSame( array( 'items.0.text' => 'MZ button-group item text', 'items.0.link' => 'https://example.com/mz-button-group-item' ), $this->values( $by['button-group'][0] ) );
		self::assertSame( array( 'before_number_text', 'after_number_text' ), array_keys( $by['numbers'][0]['fields'] ), 'Prefix and suffix did not show on the page.' );
		self::assertArrayNotHasKey( 'fields', $by['star-rating'][0] );
		self::assertArrayNotHasKey( 'fields', $by['row'][0], 'Rows are structure.' );

		// Every field read is one the measured page showed.
		$shown = $this->fx['shown'];
		$alias = array( 'photo.photo' => 'photo.photo_src', 'box.heading' => 'box.heading' );
		$count = 0;
		foreach ( $els as $el ) {
			foreach ( array_keys( $el['fields'] ?? array() ) as $name ) {
				$type = $el['parent'] === $this->module( $this->layout(), 'box' )->node ? 'box' : $el['type'];
				$key  = $type . '.' . preg_replace( '~\.\d+\.~', '.', $name );
				$key  = $alias[ $key ] ?? $key;
				self::assertArrayHasKey( $key, $shown, $key );
				self::assertTrue( $shown[ $key ], $key . ' showed on the measured page.' );
				++$count;
			}
		}
		self::assertSame( 23, $count );
		foreach ( $els as $el ) {
			self::assertArrayNotHasKey( 'note', $el, 'Only measured profile entries.' );
		}
	}

	public function testLockedElementsWithTheirReason(): void {
		$els    = $this->outline( $this->fixturePage( 104, $this->fx ) );
		$locked = array();
		foreach ( $els as $el ) {
			if ( ! empty( $el['locked'] ) ) {
				$locked[ $el['type'] ] = $el['reason'];
				self::assertArrayNotHasKey( 'fields', $el );
			}
		}
		self::assertSame(
			array(
				'html'           => 'code element',
				'widget'         => 'wordpress widget',
				'video'          => 'code element',
				'sidebar'        => 'dynamic value',
				'reusable-block' => 'global element',
			),
			$locked
		);
		$reusable = $els[ count( $els ) - 1 ];
		self::assertTrue( $reusable['global'] );

		$open = $this->outline( $this->fixturePage( 105, $this->fx ), array( 'include_locked' => false ) );
		self::assertSame( count( $els ) - 5, count( $open ) );
		self::assertSame( array(), array_filter( array_column( $open, 'locked' ) ) );
	}

	public function testNoCodeStylingOrAttributesInTheAnswer(): void {
		$answer = AB_MCP_Tools_Builders::get_builder_layout( array( 'id' => $this->fixturePage( 106, $this->fx )->ID ) );
		$flat   = $this->flat( $answer );
		foreach ( array( 'mzRoh', 'Rohes HTML', 'MZ code', 'Widget-Text MZ', 'Widget-Titel MZ', 'nonces', 'photo_src', 'link_type', 'show_caption', 'typography', 'bg_type', 'MZ callout id', 'MZ callout class', 'node_label', 'visibility_user_capability', 'MZ audio link' ) as $needle ) {
			self::assertStringNotContainsString( $needle, $flat );
		}
	}

	public function testTheProfileCannotHandOutCodeWhateverAFilterAdds(): void {
		add_filter(
			'ab_mcp_builder_element_profiles',
			static function ( $profile, $builder ) {
				if ( 'beaver' === $builder ) {
					$profile['elements']['rich-text']['fields']['bb_js_code']        = array( 'kind' => 'text' );
					$profile['elements']['rich-text']['fields']['class']             = array( 'kind' => 'text' );
					$profile['elements']['rich-text']['fields']['custom_attributes'] = array( 'kind' => 'text' );
					$profile['elements']['button']['fields']['button']               = array( 'kind' => 'text' );
				}
				return $profile;
			},
			10,
			2
		);
		$layout = $this->layout();
		$rich   = $this->module( $layout, 'rich-text' );
		$rich->settings->bb_js_code = 'window.mzKnoten=1;';
		$rich->settings->class      = 'mz-knoten-klasse';
		$out = $this->flat( $this->outline( $this->pageWith( 107, $layout ) ) );
		self::assertStringNotContainsString( 'mzKnoten', $out );
		self::assertStringNotContainsString( 'mz-knoten-klasse', $out );
		self::assertStringContainsString( 'Mo–Fr 9–18 Uhr', $out, 'The entry itself still reads.' );
	}

	public function testFieldsFollowTheSettingsThatShowThem(): void {
		$layout = $this->layout();
		$this->module( $layout, 'button' )->settings->click_action = 'popup';
		$photo                         = $this->module( $layout, 'photo' );
		$photo->settings->show_caption = '0';
		$photo->settings->link_type    = 'lightbox';
		$els = $this->byId( $this->outline( $this->pageWith( 108, $layout ) ) );
		self::assertSame( array( 'text' ), array_keys( $els[ $this->module( $layout, 'button' )->node ]['fields'] ), 'A popup button has no link.' );
		self::assertSame( array( 'photo' ), array_keys( $els[ $photo->node ]['fields'] ), 'No caption shown, no link.' );

		$photo->settings->photo_source = 'url';
		$els = $this->byId( $this->outline( $this->pageWith( 109, $layout ) ) );
		self::assertArrayNotHasKey( 'fields', $els[ $photo->node ], 'The library image is not shown for a URL source.' );

		$video                       = $this->module( $layout, 'video' );
		$video->settings->video_type = 'media_library';
		$els = $this->byId( $this->outline( $this->pageWith( 110, $layout ) ) );
		self::assertArrayNotHasKey( 'locked', $els[ $video->node ], 'A media-library video carries no embed code.' );
	}

	public function testGlobalNodesShowTheirTemplateAndHideTheirChildren(): void {
		$layout = $this->layout();
		$box    = $this->module( $layout, 'box' );
		$box->template_id = 'mz5f0c2a1e';
		$box->global      = 77;
		$els = $this->byId( $this->outline( $this->pageWith( 111, $layout ) ) );
		self::assertTrue( $els[ $box->node ]['locked'] );
		self::assertSame( 'global element', $els[ $box->node ]['reason'] );
		self::assertTrue( $els[ $box->node ]['global'] );
		self::assertSame( 'its content is post #77', $els[ $box->node ]['note'] );
		self::assertNotContains( 'Ueberschrift in der Box', array_map( 'strval', array_column( array_column( array_column( $els, 'fields' ), 'heading' ), 'value' ) ), 'The child of a locked element is not listed.' );
		self::assertCount( 24, $els );
	}

	public function testAFieldConnectedToDynamicDataLocksItsModule(): void {
		$layout  = $this->layout();
		$heading = $this->module( $layout, 'heading' );
		// The shape Beaver's own rich-text template reads (modules/rich-text/includes/frontend.php:17-19).
		$heading->settings->connections = array( 'heading' => (object) array( 'object' => 'post', 'property' => 'title' ) );
		$els = $this->byId( $this->outline( $this->pageWith( 112, $layout ) ) );
		self::assertSame( 'dynamic value', $els[ $heading->node ]['reason'] );

		$heading->settings->connections = array( 'heading' => '', 'link' => '' );
		$els = $this->byId( $this->outline( $this->pageWith( 113, $layout ) ) );
		self::assertArrayNotHasKey( 'locked', $els[ $heading->node ], 'Empty connections link nothing.' );
	}

	public function testAShortcodeInATextLocksItsModule(): void {
		$layout = $this->layout();
		$rich   = $this->module( $layout, 'rich-text' );
		$rich->settings->text = '<p>Galerie: [gallery ids="4"]</p>';
		$els = $this->byId( $this->outline( $this->pageWith( 114, $layout ) ) );
		self::assertSame( 'shortcode', $els[ $rich->node ]['reason'], 'Beaver runs shortcodes over the rendered layout.' );
	}

	public function testUnknownModulesAndBrokenNodes(): void {
		$layout = $this->layout();
		$box    = $this->module( $layout, 'box' );
		$box->settings->type = 'pro-tabs';
		$orphan = clone $this->module( $layout, 'cta' );
		$orphan->node   = 'mzwaise00001';
		$orphan->parent = 'gibtesnicht1';
		$layout[ $orphan->node ] = $orphan;
		$loop         = clone $orphan;
		$loop->node   = 'mzschleife01';
		$loop->parent = 'mzschleife01';
		$layout[ $loop->node ] = $loop;
		$layout['kaputt'] = 'not a node';
		$els = $this->byId( $this->outline( $this->pageWith( 115, $layout ) ) );
		self::assertSame( 'unknown element type', $els[ $box->node ]['reason'] );
		self::assertSame( 'pro-tabs', $els[ $box->node ]['type'] );
		self::assertCount( 24, $els, 'The unknown module\'s child, the orphan and the self-parented node are not listed.' );
		self::assertArrayNotHasKey( 'mzwaise00001', $els );
		self::assertArrayNotHasKey( 'mzschleife01', $els );
	}

	public function testUnreadableDataGivesAnEmptyOutline(): void {
		$meta                     = $this->asLoaded( $this->fx['meta'] );
		$meta['_fl_builder_data'] = 'a:1:{broken';
		$post                     = $this->fixturePage( 116, $this->fx, $meta );
		self::assertSame( array(), $this->outline( $post ) );
		$answer = AB_MCP_Tools_Builders::get_builder_layout( array( 'id' => 116 ) );
		self::assertSame( 'beaver', $answer['builder'] );
		self::assertSame( array(), $answer['elements'] );
	}

	public function testLimitsOfTheOutline(): void {
		$post = $this->fixturePage( 117, $this->fx );
		self::assertCount( 4, $this->outline( $post, array( 'max_elements' => 4 ) ) );
		$layout = $this->layout();
		$this->module( $layout, 'rich-text' )->settings->text = '<p>' . str_repeat( 'Ä', 150 ) . '</p>';
		$els  = $this->byId( $this->outline( $this->pageWith( 118, $layout ), array( 'max_field_chars' => 100 ) ) );
		$text = $els[ $this->module( $layout, 'rich-text' )->node ]['fields']['text'];
		self::assertSame( str_repeat( 'Ä', 100 ), $text['value'] );
		self::assertTrue( $text['truncated'] );
	}

	public function testDraftDiffers(): void {
		$adapter = new AB_MCP_Builder_Adapter_Beaver();
		$post    = $this->fixturePage( 119, $this->fx );
		self::assertFalse( $adapter->draft_differs( $post ), 'Published and draft are the same after save_layout() (measured).' );
		$hash = AB_MCP_Builders::layout_hash( $post );

		$meta                      = $this->asLoaded( $this->fx['meta'] );
		$meta['_fl_builder_draft'] = $this->asLoaded( array( 'x' => $this->fx['draft_changed']['_fl_builder_draft'] ) )['x'];
		$changed                   = $this->fixturePage( 120, $this->fx, $meta );
		self::assertTrue( $adapter->draft_differs( $changed ) );
		self::assertNotSame( $hash, AB_MCP_Builders::layout_hash( $changed ), 'The hash covers the draft.' );
		$answer = AB_MCP_Tools_Builders::get_builder_layout( array( 'id' => 120 ) );
		self::assertTrue( $answer['draft_differs'] );
		self::assertStringNotContainsString( 'Mo–Sa', $this->flat( $answer['elements'] ), 'The outline is the published layout.' );

		unset( $meta['_fl_builder_draft'] );
		self::assertFalse( $adapter->draft_differs( $this->fixturePage( 121, $this->fx, $meta ) ), 'No draft: the editor starts from the published layout.' );
	}

	public function testTheToolAnswersForABeaverPage(): void {
		$this->fixturePage( 122, $this->fx );
		$answer = AB_MCP_Tools_Builders::get_builder_layout( array( 'id' => 122 ) );
		self::assertSame( 'beaver', $answer['builder'] );
		self::assertSame( 'Beaver Builder', $answer['builder_name'] );
		self::assertSame( 'B', $answer['storage'] );
		self::assertSame( 'read', $answer['support'] );
		self::assertTrue( $answer['verified'] );
		self::assertFalse( $answer['draft_differs'] );
		self::assertSame( 25, $answer['element_count'] );
		self::assertSame( array( 'meta:_fl_builder_enabled' ), $answer['detected_by'] );
		self::assertStringContainsString( 'post_content is only a copy', implode( ' ', $answer['notes'] ) );
		foreach ( $answer['notes'] as $note ) {
			self::assertStringNotContainsString( AB_MCP_Block_Reader::NOTE_UNMEASURED, $note );
		}
	}

	public function testContentUpdatesAreRefusedWithTheWay(): void {
		$post  = $this->fixturePage( 123, $this->fx );
		$guard = AB_MCP_Builders::content_update_guard( $post );
		self::assertTrue( $guard['block'], 'post_content is only a copy (measured).' );
		self::assertStringContainsString( 'wp_get_builder_layout', $guard['message'] );
		self::assertStringContainsString( 'Beaver Builder editor', $guard['message'] );

		$this->builders( array(), array( 'beaver' ) );
		self::assertFalse( AB_MCP_Builders::content_update_guard( get_post( 123 ) )['block'], 'Inactive: the site shows post_content.' );
		self::assertNotInstanceOf( AB_MCP_Builder_Adapter_Beaver::class, AB_MCP_Builders::for_post( get_post( 123 ) ) );
	}
}
