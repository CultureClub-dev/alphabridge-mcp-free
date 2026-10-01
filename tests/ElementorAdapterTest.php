<?php
/**
 * The Elementor reader: the measured V3 and V4 pages, what it never hands
 * out (code, attributes, ids, styling, secrets, dynamic values, locked
 * widgets), and the two ways it knows a widget's fields — Elementor's own
 * registered controls where Elementor is loaded, its table otherwise.
 *
 * "Elementor loaded" is the stand-in in elementor-plugin-stub.php with a
 * manager each test builds from control arrays in the shape Elementor 4.3.3
 * returns them (name, type, tab, default; includes/managers/controls.php:
 * 817-848). Control names, types and defaults are copied from Elementor's
 * code where the test names a core widget (sources at each one).
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Block_Reader;
use AB_MCP_Builder_Adapter_Elementor;
use AB_MCP_Builders;
use AB_MCP_Tools_Builders;
use AB_MCP_Tools_Content;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/elementor-plugin-stub.php';

final class ElementorAdapterTest extends TestCase {

	use BuilderTestHelpers;

	protected function setUp(): void {
		ab_test_reset();
		\Elementor\Plugin::$instance = null;
		ab_test_add_user( 7 );
		$GLOBALS['ab_test_current_user'] = 7;
		$this->mayDoAnything();
		$this->builders( array( 'elementor' ) );
	}

	protected function tearDown(): void {
		\Elementor\Plugin::$instance = null;
	}

	/* ------------------------------------------------------------ helpers */

	private function adapter(): AB_MCP_Builder_Adapter_Elementor {
		$adapter = AB_MCP_Builders::adapter_for_builder( 'elementor' );
		self::assertInstanceOf( AB_MCP_Builder_Adapter_Elementor::class, $adapter );
		return $adapter;
	}

	/** A page from a fixture of tests/fixtures/builders/elementor/, with extra meta. */
	private function fixturePage( int $id, string $name, array $extra = array(), string $meta_key = 'meta' ): object {
		$f = $this->fixture( $name, 'elementor' );
		return $this->page( $id, $f['content'], array_merge( $f[ $meta_key ], $extra ) );
	}

	/** A page with these elements as _elementor_data. */
	private function elementorPage( int $id, array $elements, string $content = '' ): object {
		return $this->page(
			$id,
			$content,
			array(
				'_elementor_edit_mode' => 'builder',
				'_elementor_data'      => (string) json_encode( $elements ),
			)
		);
	}

	private function outline( object $post, array $o = array() ): array {
		return $this->adapter()->outline( $post, $o );
	}

	/** Elements by id. */
	private function byId( array $elements ): array {
		$out = array();
		foreach ( $elements as $e ) {
			$out[ $e['id'] ] = $e;
		}
		return $out;
	}

	private static function widget( string $id, string $type, array $settings, array $more = array() ): array {
		return array_merge(
			array(
				'id'         => $id,
				'elType'     => 'widget',
				'settings'   => $settings,
				'elements'   => array(),
				'widgetType' => $type,
			),
			$more
		);
	}

	private static function container( string $id, array $children, string $el_type = 'container' ): array {
		return array(
			'id'       => $id,
			'elType'   => $el_type,
			'settings' => array(),
			'elements' => $children,
			'isInner'  => false,
		);
	}

	/**
	 * "Elementor is loaded" with these registered types (widget type or
	 * element type => object with get_controls()).
	 */
	private function loadElementor( array $types ): void {
		$plugin                   = new \Elementor\Plugin();
		$plugin->elements_manager = new class( $types ) {
			/** @var array */
			private $types;

			public function __construct( array $types ) {
				$this->types = $types;
			}

			// The signature of Elements_Manager::get_element() in 4.3.3.
			public function get_element( string $el_type, ?string $widget_type = null ) {
				$key = 'widget' === $el_type ? (string) $widget_type : $el_type;
				return $this->types[ $key ] ?? null;
			}
		};
		\Elementor\Plugin::$instance = $plugin;
	}

	/** A registered type with these controls (keyed by name, as get_controls() returns them). */
	private static function type( array $controls ): object {
		return new class( $controls ) {
			/** @var array */
			private $controls;

			public function __construct( array $controls ) {
				$this->controls = $controls;
			}

			public function get_controls( $control_id = null ) {
				return $this->controls;
			}
		};
	}

	/** One control, as Elementor stores it in the stack. */
	private static function control( string $name, string $type, $default = '', string $tab = 'content', array $more = array() ): array {
		return array_merge(
			array(
				'name'    => $name,
				'type'    => $type,
				'tab'     => $tab,
				'default' => $default,
			),
			$more
		);
	}

	/** Controls every widget gets on the advanced tab [SVN elementor@4.3.3 includes/widgets/common-base.php]. */
	private static function advancedControls(): array {
		return array(
			'_grid_column_custom' => self::control( '_grid_column_custom', 'text', '', 'advanced' ),
			'_element_id'         => self::control( '_element_id', 'text', '', 'advanced' ),
			'_css_classes'        => self::control( '_css_classes', 'text', '', 'advanced' ),
			'_mask_image'         => self::control( '_mask_image', 'media', array( 'url' => '', 'id' => '', 'size' => '' ), 'advanced' ),
			'custom_css'          => self::control( 'custom_css', 'code', '', 'advanced' ),
			'custom_attributes'   => self::control( 'custom_attributes', 'textarea', '', 'advanced' ),
		);
	}

	/** The heading widget's content controls [SVN elementor@4.3.3 includes/widgets/heading.php:181-245]. */
	private static function headingType(): object {
		return self::type(
			array_merge(
				array(
					'title'       => self::control( 'title', 'textarea', 'Add Your Heading Text Here', 'content', array( 'dynamic' => array( 'active' => true ) ) ),
					'link'        => self::control( 'link', 'url', array( 'url' => '', 'is_external' => '', 'nofollow' => '', 'custom_attributes' => '' ) ),
					'header_size' => self::control( 'header_size', 'select', 'h2' ),
				),
				self::advancedControls()
			)
		);
	}

	/** The button widget's content controls [SVN elementor@4.3.3 includes/widgets/traits/button-trait.php:82-210]. */
	private static function buttonType(): object {
		return self::type(
			array_merge(
				array(
					'text'          => self::control( 'text', 'text', 'Click here' ),
					'link'          => self::control( 'link', 'url', array( 'url' => '#', 'is_external' => '', 'nofollow' => '', 'custom_attributes' => '' ) ),
					'selected_icon' => self::control( 'selected_icon', 'icons', array() ),
					'button_css_id' => self::control( 'button_css_id', 'text', '' ),
				),
				self::advancedControls()
			)
		);
	}

	/* --------------------------------------------------------- registration */

	public function testTheFreeCoreRegistersTheElementorReader(): void {
		$adapter = $this->adapter();
		self::assertSame( 'elementor', $adapter->id() );
		self::assertSame( 'Elementor', $adapter->name() );
		self::assertSame( array( 'elementor' ), $adapter->builders() );
		self::assertTrue( $adapter->verified(), 'The measured pages are read as Elementor saved them.' );
	}

	public function testRecognitionStorageCopiesAndDataVersion(): void {
		$post    = $this->fixturePage( 100, 'klassisch', array( '_elementor_edit_mode' => 'builder' ) );
		$adapter = $this->adapter();
		self::assertTrue( $adapter->detect( $post ) );
		self::assertSame( 'B', $adapter->storage( $post ) );
		self::assertSame( '4.3.3', $adapter->data_version( $post ), 'From _elementor_version, the data format.' );
		self::assertSame(
			array(
				array( 'loc' => 'meta:_elementor_data', 'shown' => true, 'role' => 'source' ),
				array( 'loc' => 'post_content', 'shown' => false, 'role' => 'copy' ),
			),
			$adapter->copies( $post )
		);
		$raw = $adapter->raw( $post );
		self::assertSame( array( 'meta:_elementor_data', 'post_content' ), array_keys( $raw ) );
		self::assertSame( $this->fixture( 'klassisch', 'elementor' )['meta']['_elementor_data'], $raw['meta:_elementor_data'], 'Exactly as stored.' );
		self::assertArrayNotHasKey( 'meta:_elementor_css', $raw, 'Caches are not copies of the page.' );
		self::assertArrayNotHasKey( 'meta:_elementor_element_cache', $raw );
		self::assertSame( $adapter, AB_MCP_Builders::for_post( $post ) );

		$plain = $this->page( 101, '<p>x</p>', array( '_elementor_edit_mode' => '' ) );
		self::assertFalse( $adapter->detect( $plain ), 'Switched back to the WordPress editor.' );
	}

	/* ------------------------------------------------------ measured pages */

	public function testTheMeasuredClassicPage(): void {
		$post = $this->fixturePage( 102, 'klassisch', array( '_elementor_edit_mode' => 'builder' ) );
		self::assertSame(
			array(
				array( 'id' => 'c0ffee1', 'type' => 'container', 'parent' => null, 'depth' => 0 ),
				array(
					'id'     => 'a1b2c3d',
					'type'   => 'heading',
					'parent' => 'c0ffee1',
					'depth'  => 1,
					'fields' => array( 'title' => array( 'kind' => 'heading', 'value' => 'Willkommen bei Alpine Bikes' ) ),
				),
				array(
					'id'     => 'b2c3d4e',
					'type'   => 'text-editor',
					'parent' => 'c0ffee1',
					'depth'  => 1,
					'fields' => array( 'editor' => array( 'kind' => 'html', 'value' => 'Öffnungszeiten: Mo–Fr 9–18 Uhr' ) ),
				),
				array(
					'id'     => 'c3d4e5f',
					'type'   => 'button',
					'parent' => 'c0ffee1',
					'depth'  => 1,
					'fields' => array(
						'text' => array( 'kind' => 'text', 'value' => 'Jetzt reservieren' ),
						'link' => array( 'kind' => 'url', 'value' => 'https://example.com/reservieren' ),
					),
				),
			),
			$this->outline( $post )
		);
		self::assertStringNotContainsString( 'header_size', $this->flat( $this->outline( $post ) ), 'Styling is not a field.' );
	}

	public function testTheMeasuredAtomicPage(): void {
		$post = $this->fixturePage( 103, 'atomar' );
		self::assertSame(
			array(
				array( 'id' => 'f00d001', 'type' => 'e-flexbox', 'parent' => null, 'depth' => 0 ),
				array(
					'id'     => 'f00d002',
					'type'   => 'e-heading',
					'parent' => 'f00d001',
					'depth'  => 1,
					'fields' => array( 'title' => array( 'kind' => 'heading', 'value' => 'Willkommen bei Alpine Bikes' ) ),
				),
				array(
					'id'     => 'f00d003',
					'type'   => 'e-paragraph',
					'parent' => 'f00d001',
					'depth'  => 1,
					'fields' => array( 'paragraph' => array( 'kind' => 'html', 'value' => 'Öffnungszeiten: Mo–Fr 9–18 Uhr' ) ),
				),
				array(
					'id'     => 'f00d004',
					'type'   => 'e-button',
					'parent' => 'f00d001',
					'depth'  => 1,
					'fields' => array( 'text' => array( 'kind' => 'text', 'value' => 'Jetzt reservieren' ) ),
				),
			),
			$this->outline( $post ),
			'The $$type wrapper is read, the tag (h1) is styling.'
		);
	}

	public function testTheMeasuredAtomicDefaults(): void {
		// Empty link and image values, as Elementor stores the defaults.
		$els = $this->byId( $this->outline( $this->fixturePage( 104, 'atomar-standardwerte' ) ) );
		self::assertSame( array( 'title' => array( 'kind' => 'heading', 'value' => 'This is a title' ) ), $els['d000001']['fields'] );
		self::assertSame( array( 'paragraph' => array( 'kind' => 'html', 'value' => 'Type your paragraph here' ) ), $els['d000002']['fields'] );
		self::assertSame( array( 'text' => array( 'kind' => 'text', 'value' => 'Click here' ) ), $els['d000003']['fields'] );
		self::assertArrayNotHasKey( 'fields', $els['d000004'], 'An image without a source has nothing to show.' );
		self::assertArrayNotHasKey( 'note', $els['d000004'] );
	}

	public function testTheToolOnTheMeasuredClassicPage(): void {
		$this->fixturePage( 105, 'klassisch', array( '_elementor_edit_mode' => 'builder' ) );
		$out = AB_MCP_Tools_Builders::get_builder_layout( array( 'id' => 105 ) );
		self::assertSame( 'elementor', $out['builder'] );
		self::assertSame( 'Elementor', $out['builder_name'] );
		self::assertTrue( $out['builder_active'] );
		self::assertSame( '4.3.3', $out['data_version'] );
		self::assertSame( 'B', $out['storage'] );
		self::assertSame( array( 'meta:_elementor_edit_mode' ), $out['detected_by'] );
		self::assertSame( 'meta:_elementor_data', $out['copies'][0]['loc'] );
		self::assertSame( 4, $out['element_count'] );
		self::assertSame( 'read', $out['support'] );
		self::assertTrue( $out['verified'] );
		self::assertSame( array(), $out['write_via'], 'Nothing in the free plugin writes Elementor data.' );
		self::assertStringContainsString( 'post_content is only a copy', implode( ' ', $out['notes'] ) );
		self::assertSame( AB_MCP_Builders::layout_hash( get_post( 105 ) ), $out['layout_hash'] );
	}

	public function testTheChangeInPlaceChangesTheHashAndTheOutline(): void {
		// ergebnis-4: one text-editor changed through Document::save().
		$before = $this->fixturePage( 106, 'gezielt', array(), 'meta_before' );
		$hash   = AB_MCP_Builders::layout_hash( $before );
		self::assertSame( $hash, AB_MCP_Builders::layout_hash( get_post( 106 ) ), 'Stable while nothing changes.' );
		self::assertSame( 'Öffnungszeiten: Mo–Fr 9–18 Uhr', $this->byId( $this->outline( $before ) )['b2c3d4e']['fields']['editor']['value'] );

		$after = $this->fixturePage( 106, 'gezielt' );
		self::assertNotSame( $hash, AB_MCP_Builders::layout_hash( $after ) );
		self::assertSame( 'Öffnungszeiten: Mo–Sa 9–17 Uhr', $this->byId( $this->outline( $after ) )['b2c3d4e']['fields']['editor']['value'] );
		self::assertSame( 'b2c3d4e', $this->byId( $this->outline( $after ) )['b2c3d4e']['id'], 'Elementor ids survive the change.' );

		// The text copy counts too: a hash over every stored copy.
		$copy_changed = $this->page( 106, 'other copy', $this->fixture( 'gezielt', 'elementor' )['meta'] );
		self::assertNotSame( AB_MCP_Builders::layout_hash( $after ), AB_MCP_Builders::layout_hash( $copy_changed ) );
	}

	/* -------------------------------------------- widgets from the table */

	public function testTheTableWidgetsAreReadAndSayTheyAreUnmeasured(): void {
		// Control names from Elementor 4.3.3 (see WIDGETS); the page is built here, not measured.
		$post = $this->elementorPage(
			107,
			array(
				self::container(
					'aa00001',
					array(
						self::widget( 'aa00002', 'image', array( 'image' => array( 'url' => 'https://example.com/bike.jpg', 'id' => 31 ), 'caption' => 'Unser Laden', 'link' => array( 'url' => '/laden' ) ) ),
						self::widget( 'aa00003', 'icon-box', array( 'title_text' => 'Service', 'description_text' => 'Wir <em>reparieren</em> alles', 'link' => array( 'url' => '' ) ) ),
						self::widget(
							'aa00004',
							'icon-list',
							array(
								'icon_list' => array(
									array( '_id' => 'x1', 'text' => 'Velos', 'link' => array( 'url' => '/velos' ), 'selected_icon' => array( 'value' => 'fas fa-check' ) ),
									array( '_id' => 'x2', 'text' => 'E-Bikes' ),
								),
							)
						),
						self::widget( 'aa00005', 'image-box', array( 'image' => array( 'url' => 'https://example.com/x.png', 'id' => '' ), 'title_text' => 'Miete', 'description_text' => 'Ab CHF 30' ) ),
						self::widget( 'aa00006', 'testimonial', array( 'testimonial_content' => 'Top!', 'testimonial_name' => 'Anna', 'testimonial_job' => 'Kundin', 'testimonial_image' => array( 'id' => 9 ) ) ),
					)
				),
			)
		);
		$els = $this->byId( $this->outline( $post ) );
		self::assertSame(
			array(
				'image'   => array( 'kind' => 'image', 'value' => 31 ),
				'caption' => array( 'kind' => 'text', 'value' => 'Unser Laden' ),
				'link'    => array( 'kind' => 'url', 'value' => '/laden' ),
			),
			$els['aa00002']['fields']
		);
		self::assertSame(
			array(
				'title_text'       => array( 'kind' => 'heading', 'value' => 'Service' ),
				'description_text' => array( 'kind' => 'text', 'value' => 'Wir reparieren alles' ),
			),
			$els['aa00003']['fields'],
			'An empty link is no field; "description" is not a script.'
		);
		self::assertSame(
			array(
				'icon_list.0.text' => array( 'kind' => 'text', 'value' => 'Velos' ),
				'icon_list.0.link' => array( 'kind' => 'url', 'value' => '/velos' ),
				'icon_list.1.text' => array( 'kind' => 'text', 'value' => 'E-Bikes' ),
			),
			$els['aa00004']['fields'],
			'Repeater items by position.'
		);
		self::assertSame( 'https://example.com/x.png', $els['aa00005']['fields']['image']['value'], 'An image without attachment id by its address.' );
		self::assertSame( array( 'testimonial_content', 'testimonial_image', 'testimonial_name', 'testimonial_job' ), array_keys( $els['aa00006']['fields'] ) );
		foreach ( array( 'aa00002', 'aa00003', 'aa00004', 'aa00005', 'aa00006' ) as $id ) {
			self::assertSame( AB_MCP_Block_Reader::NOTE_UNMEASURED, $els[ $id ]['note'], $id );
		}
		self::assertArrayNotHasKey( 'note', $els['aa00001'] );

		$out = AB_MCP_Tools_Builders::get_builder_layout( array( 'id' => 107 ) );
		self::assertFalse( $out['verified'], 'Unmeasured widgets make the answer unverified.' );
		self::assertStringContainsString( '5 elements were read without a measured profile', implode( ' ', $out['notes'] ) );
	}

	public function testWithoutElementorAWidgetOutsideTheTableIsLocked(): void {
		$post = $this->elementorPage(
			108,
			array(
				self::container(
					'ab00001',
					array(
						self::widget( 'ab00002', 'eael-fancy-text', array( 'eael_fancy_text_prefix' => 'Hello', 'eael_fancy_text_style' => 'style-1' ) ),
						self::widget( 'ab00003', 'heading', array( 'title' => 'Still read' ) ),
					)
				),
			)
		);
		$els = $this->byId( $this->outline( $post ) );
		self::assertTrue( $els['ab00002']['locked'] );
		self::assertSame( 'unknown element type', $els['ab00002']['reason'] );
		self::assertSame( AB_MCP_Builder_Adapter_Elementor::NOTE_NOT_LOADED, $els['ab00002']['note'] );
		self::assertArrayNotHasKey( 'fields', $els['ab00002'] );
		self::assertSame( 'Still read', $els['ab00003']['fields']['title']['value'] );
		self::assertStringNotContainsString( 'Hello', $this->flat( $els ), 'Without its controls nothing of an unknown widget is read.' );
	}

	/* ---------------------------------------------------------------- locks */

	public function testLockedWidgetsAreListedWithoutContentAndTheirChildrenSkipped(): void {
		$post = $this->elementorPage(
			109,
			array(
				self::container(
					'ac00001',
					array(
						self::widget( 'ac00002', 'html', array( 'html' => '<script>alert(1)</script>' ) ),
						self::widget( 'ac00003', 'shortcode', array( 'shortcode' => '[contact-form-7 id="1"]' ) ),
						self::widget( 'ac00004', 'global', array(), array( 'templateID' => 812 ) ),
						self::widget( 'ac00005', 'template', array( 'template_id' => '77' ) ),
						self::widget( 'ac00006', 'e-component', array( 'component_instance' => array( '$$type' => 'component-instance', 'value' => array( 'component_id' => array( '$$type' => 'number', 'value' => 55 ) ) ) ) ),
						self::widget( 'ac00007', 'form', array( 'email_to' => 'owner@example.com', 'form_name' => 'Kontakt' ) ),
						self::widget( 'ac00008', 'wp-widget-custom_html', array( 'wp' => array( 'content' => '<iframe src="x"></iframe>' ) ) ),
						array(
							'id'       => 'ac00009',
							'elType'   => 'e-form',
							'settings' => array( 'webhook_url' => array( '$$type' => 'string', 'value' => 'https://hooks.example.com/secret' ) ),
							'elements' => array( self::widget( 'ac00010', 'e-heading', array( 'title' => array( '$$type' => 'escaped-html', 'value' => 'Inside the form' ) ) ) ),
						),
					)
				),
			)
		);
		$els = $this->byId( $this->outline( $post ) );
		$expect = array(
			'ac00002' => array( 'html widget', null, null ),
			'ac00003' => array( 'shortcode', null, null ),
			'ac00004' => array( 'global element', true, 'its content is post #812' ),
			'ac00005' => array( 'global element', true, 'its content is post #77' ),
			'ac00006' => array( 'global element', true, 'its content is post #55' ),
			'ac00007' => array( 'form', null, null ),
			'ac00008' => array( 'WordPress widget', null, 'a classic WordPress widget placed in Elementor; change it in the Elementor editor' ),
			'ac00009' => array( 'form', null, null ),
		);
		foreach ( $expect as $id => list( $reason, $global, $note ) ) {
			self::assertTrue( $els[ $id ]['locked'], $id );
			self::assertSame( $reason, $els[ $id ]['reason'], $id );
			self::assertArrayNotHasKey( 'fields', $els[ $id ], $id );
			self::assertSame( $global, $els[ $id ]['global'] ?? null, $id );
			self::assertSame( $note, $els[ $id ]['note'] ?? null, $id );
		}
		self::assertArrayNotHasKey( 'ac00010', $els, 'Children of a locked element are not listed.' );
		$flat = $this->flat( $els );
		foreach ( array( 'alert(', 'contact-form-7', 'owner@example.com', 'iframe', 'hooks.example.com', 'Inside the form' ) as $never ) {
			self::assertStringNotContainsString( $never, $flat, $never );
		}

		$open = $this->outline( $post, array( 'include_locked' => false ) );
		self::assertSame( array( 'ac00001' ), array_column( $open, 'id' ), 'include_locked false leaves them out.' );
	}

	public function testDynamicValuesAreLeftOutAndNamed(): void {
		$post = $this->elementorPage(
			110,
			array(
				self::widget(
					'ad00001',
					'heading',
					array(
						'title'       => 'Static fallback',
						'link'        => array( 'url' => '/kontakt' ),
						'__dynamic__' => array( 'title' => '[elementor-tag id="1a2b3c4" name="post-title" settings="%7B%7D"]' ),
					)
				),
				self::widget( 'ad00002', 'e-heading', array( 'title' => array( '$$type' => 'dynamic', 'value' => array( 'name' => 'post-title', 'settings' => array() ) ) ) ),
				self::widget(
					'ad00003',
					'icon-list',
					array(
						'icon_list' => array(
							array( '_id' => 'y1', 'text' => 'Static', '__dynamic__' => array( 'text' => '[elementor-tag id="2" name="site-title" settings=""]' ) ),
						),
					)
				),
			)
		);
		$els = $this->byId( $this->outline( $post ) );
		self::assertSame( array( 'link' => array( 'kind' => 'url', 'value' => '/kontakt' ) ), $els['ad00001']['fields'] );
		self::assertSame( 'title: dynamic value, not read', $els['ad00001']['note'] );
		self::assertArrayNotHasKey( 'fields', $els['ad00002'] );
		self::assertSame( 'title: dynamic value, not read', $els['ad00002']['note'] );
		self::assertArrayNotHasKey( 'fields', $els['ad00003'] );
		self::assertSame( 'icon_list.0.text: dynamic value, not read', $els['ad00003']['note'] );
		self::assertStringNotContainsString( 'elementor-tag', $this->flat( $els ) );
	}

	/* -------------------------------------------- safety of what is read */

	#[DataProvider( 'deniedNames' )]
	public function testNamesThatAreNeverFields( string $name ): void {
		self::assertTrue( AB_MCP_Builder_Adapter_Elementor::denied_name( $name ), $name );
	}

	public static function deniedNames(): array {
		$out = array();
		foreach ( array(
			// B1 of the plan review.
			'custom_attributes', '_attributes', 'link_attr', 'css_classes', '_css_classes', 'button_css_id', 'custom_css',
			'custom_js', 'code', 'embed_code', 'html', 'html_tag', 'title_tag', 'selector', '_element_id', 'template_id', 'templateID',
			// Run together, without a separator.
			'customcss', 'boxshadowcss', 'headerscript', 'scriptsrc',
			// Scripts, styling, handlers.
			'script', 'inline_script', 'javascript', 'style', 'svg_icon', 'onclick', 'onMouseOver', 'shortcode',
			// Secrets an integration keeps in a text control.
			'api_key', 'mailchimp_api_key', 'access_token', 'client_secret', 'password', 'webhooks', 'site_key',
			'',
		) as $name ) {
			$out[ '' === $name ? '(empty)' : $name ] = array( $name );
		}
		return $out;
	}

	#[DataProvider( 'allowedNames' )]
	public function testNamesOfVisibleContentStayReadable( string $name ): void {
		self::assertFalse( AB_MCP_Builder_Adapter_Elementor::denied_name( $name ), $name );
	}

	public static function allowedNames(): array {
		$out = array();
		foreach ( array( 'title', 'editor', 'text', 'link', 'image', 'caption', 'title_text', 'description_text', 'description', 'subscription_text', 'icon_list', 'testimonial_content', 'testimonial_job', 'paragraph', 'tagline', 'hashtag', 'percentage_text', 'keyword' ) as $name ) {
			$out[ $name ] = array( $name );
		}
		return $out;
	}

	public function testTheTableCannotOpenACodeField(): void {
		// A filter (or a slip in WIDGETS) that lists code fields: they stay unread.
		add_filter(
			'ab_mcp_builder_elementor_widgets',
			static function ( $widgets ) {
				$widgets['heading']['fields']['_css_classes']      = 'text';
				$widgets['heading']['fields']['custom_attributes'] = 'text';
				$widgets['heading']['fields']['custom_css']        = 'html';
				$widgets['acme-code']                              = array( 'locked' => 'code element' );
				$widgets['acme-card']                              = array(
					'fields' => array(
						'card_title' => 'heading',
						'card_html'  => 'html',
						'items'      => array(
							'label'   => 'text',
							'item_id' => 'text',
						),
					),
				);
				return $widgets;
			}
		);
		$table = AB_MCP_Builder_Adapter_Elementor::widgets();
		self::assertSame( array( 'title' => 'heading', 'link' => 'url' ), $table['heading']['fields'] );
		self::assertSame( array( 'card_title' => 'heading', 'items' => array( 'label' => 'text' ) ), $table['acme-card']['fields'] );

		$post = $this->elementorPage(
			111,
			array(
				self::widget( 'ae00001', 'heading', array( 'title' => 'Hi', '_css_classes' => 'hero', 'custom_attributes' => 'onmouseover|alert(1)', 'custom_css' => 'selector{color:red}' ) ),
				self::widget( 'ae00002', 'acme-code', array( 'code' => 'alert(2)' ) ),
				self::widget( 'ae00003', 'acme-card', array( 'card_title' => 'Card', 'card_html' => '<b>x</b>', 'items' => array( array( 'label' => 'One', 'item_id' => 'zz' ) ) ) ),
			)
		);
		$els = $this->byId( $this->outline( $post ) );
		self::assertSame( array( 'title' => array( 'kind' => 'heading', 'value' => 'Hi' ) ), $els['ae00001']['fields'] );
		self::assertSame( 'code element', $els['ae00002']['reason'] );
		self::assertSame( array( 'card_title', 'items.0.label' ), array_keys( $els['ae00003']['fields'] ) );
		$flat = $this->flat( $els );
		foreach ( array( 'hero', 'alert(', 'color:red', 'zz' ) as $never ) {
			self::assertStringNotContainsString( $never, $flat, $never );
		}
	}

	public function testAddressesThatRunCodeAreLeftOut(): void {
		$post = $this->elementorPage(
			112,
			array(
				self::widget( 'af00001', 'button', array( 'text' => 'Go', 'link' => array( 'url' => ' JaVaScRiPt:alert(1)' ) ) ),
				self::widget( 'af00002', 'image', array( 'image' => array( 'url' => 'data:image/svg+xml;base64,PHN2Zz4=', 'id' => '' ) ) ),
				self::widget( 'af00003', 'image', array( 'image' => array( 'url' => 'data:image/png;base64,iVBORw0KGgo=', 'id' => '' ) ) ),
				self::widget( 'af00004', 'e-button', array( 'text' => array( '$$type' => 'escaped-html', 'value' => 'V4' ), 'link' => array( '$$type' => 'link', 'value' => array( 'destination' => array( '$$type' => 'url', 'value' => 'vbscript:x' ) ) ) ) ),
				self::widget( 'af00005', 'e-button', array( 'link' => array( '$$type' => 'link', 'value' => array( 'destination' => array( '$$type' => 'url', 'value' => 'https://example.com/v4' ) ) ) ) ),
				self::widget( 'af00006', 'e-button', array( 'link' => array( '$$type' => 'link', 'value' => array( 'destination' => array( '$$type' => 'query', 'value' => array( 'id' => array( '$$type' => 'number', 'value' => 14 ) ) ) ) ) ) ),
				self::widget( 'af00007', 'e-image', array( 'image' => array( '$$type' => 'image', 'value' => array( 'src' => array( '$$type' => 'image-src', 'value' => array( 'id' => array( '$$type' => 'image-attachment-id', 'value' => 88 ), 'url' => null ) ) ) ) ) ),
				self::widget( 'af00008', 'e-paragraph', array( 'paragraph' => array( '$$type' => 'html-v3', 'value' => array( 'content' => array( '$$type' => 'string', 'value' => 'Tree text' ), 'children' => array() ) ) ) ),
			)
		);
		$els = $this->byId( $this->outline( $post ) );
		self::assertSame( array( 'text' => array( 'kind' => 'text', 'value' => 'Go' ) ), $els['af00001']['fields'] );
		self::assertStringContainsString( 'link: an address that would run code, left out', $els['af00001']['note'] );
		self::assertArrayNotHasKey( 'fields', $els['af00002'], 'SVG can run script.' );
		self::assertSame( 'data:image/png;base64,iVBORw0KGgo=', $els['af00003']['fields']['image']['value'], 'A raster image inline is the picture itself.' );
		self::assertSame( array( 'text' => array( 'kind' => 'text', 'value' => 'V4' ) ), $els['af00004']['fields'] );
		self::assertStringContainsString( 'link: an address that would run code, left out', $els['af00004']['note'] );
		self::assertSame( array( 'link' => array( 'kind' => 'url', 'value' => 'https://example.com/v4' ) ), $els['af00005']['fields'] );
		self::assertSame( 'link: links to post #14', $els['af00006']['note'] );
		self::assertSame( array( 'image' => array( 'kind' => 'image', 'value' => 88 ) ), $els['af00007']['fields'] );
		self::assertArrayNotHasKey( 'fields', $els['af00008'] );
		self::assertSame( 'paragraph: rich text in a format not read yet (html-v3)', $els['af00008']['note'], 'Not read, but not silently missing either.' );
		self::assertStringNotContainsString( 'alert', $this->flat( $els ) );
	}

	public function testFieldsAreCut(): void {
		$long = str_repeat( 'Ä', 150 );
		$post = $this->elementorPage( 113, array( self::widget( 'ag00001', 'heading', array( 'title' => $long ) ) ) );
		$f    = $this->outline( $post, array( 'max_field_chars' => 100 ) )[0]['fields']['title'];
		self::assertSame( str_repeat( 'Ä', 100 ), $f['value'] );
		self::assertTrue( $f['truncated'] );
	}

	/* ---------------------------------------------------- ids and limits */

	public function testPathIdsWhereElementorsIdIsMissingDuplicatedOrLooksLikeAPath(): void {
		$post = $this->elementorPage(
			114,
			array(
				self::container(
					'same001',
					array(
						self::widget( 'same001', 'heading', array( 'title' => 'A' ) ),
						self::widget( 'e1', 'heading', array( 'title' => 'B' ) ),
						array( 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => array( 'title' => 'C' ) ),
						self::widget( 'ok00001', 'heading', array( 'title' => 'D' ) ),
					)
				),
			)
		);
		$out = $this->outline( $post );
		self::assertSame( array( 'e0', 'e0.0', 'e0.1', 'e0.2', 'ok00001' ), array_column( $out, 'id' ) );
		self::assertSame( array( null, 'e0', 'e0', 'e0', 'e0' ), array_column( $out, 'parent' ) );
		self::assertSame( array( 0, 1, 1, 1, 1 ), array_column( $out, 'depth' ) );
	}

	public function testMaxElementsStopsTheWalk(): void {
		$children = array();
		for ( $i = 0; $i < 10; $i++ ) {
			$children[] = self::widget( sprintf( 'ah%05d', $i ), 'heading', array( 'title' => 'T' . $i ) );
		}
		$post = $this->elementorPage( 115, array( self::container( 'ahroot0', $children ) ) );
		self::assertCount( 4, $this->outline( $post, array( 'max_elements' => 4 ) ) );
		$out = AB_MCP_Tools_Builders::get_builder_layout( array( 'id' => 115, 'max_elements' => 5 ) );
		self::assertTrue( $out['truncated'] );
		self::assertSame( 5, $out['element_count'] );
	}

	public function testUnreadableAndEmptyData(): void {
		$broken = $this->page( 116, 'copy', array( '_elementor_edit_mode' => 'builder', '_elementor_data' => '[{"id":"x"' ) );
		$out    = $this->outline( $broken );
		self::assertCount( 1, $out );
		self::assertTrue( $out[0]['locked'] );
		self::assertSame( 'unreadable data', $out[0]['reason'] );
		self::assertSame( array(), $this->outline( $broken, array( 'include_locked' => false ) ) );

		$empty = $this->page( 117, 'copy', array( '_elementor_edit_mode' => 'builder', '_elementor_data' => '' ) );
		self::assertSame( array(), $this->outline( $empty ) );
		$none = $this->page( 118, 'copy', array( '_elementor_edit_mode' => 'builder' ) );
		self::assertSame( array(), $this->outline( $none ) );
		$list = $this->page( 119, 'copy', array( '_elementor_edit_mode' => 'builder', '_elementor_data' => '[]' ) );
		self::assertSame( array(), $this->outline( $list ) );
	}

	/* ------------------------------------------------- Elementor loaded */

	public function testWithElementorLoadedTheContentControlsDecide(): void {
		$this->loadElementor(
			array(
				'container' => self::type( array( 'link' => self::control( 'link', 'url', array( 'url' => '' ), 'layout' ) ) ),
				'heading'   => self::headingType(),
			)
		);
		$post = $this->elementorPage(
			120,
			array(
				self::container(
					'ba00001',
					array(
						self::widget(
							'ba00002',
							'heading',
							array(
								'title'               => 'Hallo',
								'header_size'         => 'h1',
								'_element_id'         => 'hero',
								'_css_classes'        => 'big',
								'_grid_column_custom' => 'span 2',
								'_mask_image'         => array( 'url' => 'https://example.com/mask.svg', 'id' => 5 ),
								'custom_attributes'   => 'onmouseover|alert(1)',
								'custom_css'          => 'selector{color:red}',
							)
						),
					)
				),
			)
		);
		$els = $this->byId( $this->outline( $post ) );
		self::assertArrayNotHasKey( 'fields', $els['ba00001'], 'The container link is a layout control.' );
		self::assertSame( array( 'title' => array( 'kind' => 'heading', 'value' => 'Hallo' ) ), $els['ba00002']['fields'], 'Kind from the table, the field from the controls.' );
		self::assertArrayNotHasKey( 'note', $els['ba00002'], 'A measured widget, nothing defaulted.' );
		$flat = $this->flat( $els );
		foreach ( array( 'hero', 'big', 'span 2', 'mask.svg', 'alert(', 'color:red' ) as $never ) {
			self::assertStringNotContainsString( $never, $flat, $never );
		}
	}

	public function testWithElementorLoadedDefaultsAreWhatTheVisitorSees(): void {
		$this->loadElementor( array( 'button' => self::buttonType() ) );
		$post = $this->elementorPage(
			121,
			array(
				self::widget( 'bb00001', 'button', array() ),
				self::widget( 'bb00002', 'button', array( 'text' => 'Buchen', 'link' => array( 'url' => '/buchen' ), 'button_css_id' => 'cta' ) ),
			)
		);
		$els = $this->byId( $this->outline( $post ) );
		self::assertSame(
			array(
				'text' => array( 'kind' => 'text', 'value' => 'Click here' ),
				'link' => array( 'kind' => 'url', 'value' => '#' ),
			),
			$els['bb00001']['fields']
		);
		self::assertSame( "text: Elementor's default, not stored on the page; link: Elementor's default, not stored on the page", $els['bb00001']['note'] );
		self::assertSame(
			array(
				'text' => array( 'kind' => 'text', 'value' => 'Buchen' ),
				'link' => array( 'kind' => 'url', 'value' => '/buchen' ),
			),
			$els['bb00002']['fields']
		);
		self::assertArrayNotHasKey( 'note', $els['bb00002'] );
		self::assertStringNotContainsString( 'cta', $this->flat( $els ), 'button_css_id is a content control, and still an id (B1).' );
	}

	public function testWithElementorLoadedAnAddOnWidgetIsReadThroughItsControls(): void {
		$this->loadElementor(
			array(
				'acme-card' => self::type(
					array_merge(
						array(
							'card_title'        => self::control( 'card_title', 'text', '' ),
							'card_text'         => self::control( 'card_text', 'wysiwyg', '' ),
							'card_link'         => self::control( 'card_link', 'url', array( 'url' => '' ) ),
							'card_image'        => self::control( 'card_image', 'media', array( 'url' => '', 'id' => '' ) ),
							'card_style'        => self::control( 'card_style', 'select', 'boxed' ),
							'custom_attributes' => self::control( 'custom_attributes', 'textarea', '' ),
							'card_css_id'       => self::control( 'card_css_id', 'text', '' ),
							'embed_code'        => self::control( 'embed_code', 'textarea', '' ),
							'api_key'           => self::control( 'api_key', 'text', '' ),
							'snippet'           => self::control( 'snippet', 'code', '' ),
							'items'             => self::control(
								'items',
								'repeater',
								array(),
								'content',
								array(
									'fields' => array(
										array( 'name' => 'label', 'type' => 'text', 'default' => '' ),
										array( 'name' => 'item_id', 'type' => 'text', 'default' => '' ),
										array( 'name' => 'item_link', 'type' => 'url', 'default' => array( 'url' => '' ) ),
									),
								)
							),
							'hover_image'       => self::control( 'hover_image', 'media', array( 'url' => '', 'id' => '' ), 'style' ),
						),
						self::advancedControls()
					)
				),
			)
		);
		$post = $this->elementorPage(
			122,
			array(
				self::widget(
					'bc00001',
					'acme-card',
					array(
						'card_title'        => 'Bike Hire',
						'card_text'         => '<p>From <strong>CHF 30</strong></p><script>alert(1)</script>',
						'card_link'         => array( 'url' => '/hire' ),
						'card_image'        => array( 'id' => 42, 'url' => 'https://example.com/a.jpg' ),
						'card_style'        => 'flat',
						'custom_attributes' => 'onmouseover|alert(2)',
						'card_css_id'       => 'x" onmouseover="alert(3)',
						'embed_code'        => '<iframe src="https://evil.example"></iframe>',
						'api_key'           => 'sk_live_123',
						'snippet'           => 'alert(4)',
						'items'             => array(
							array( '_id' => 'i1', 'label' => 'Day', 'item_id' => 'zz9', 'item_link' => array( 'url' => 'javascript:alert(5)' ) ),
							array( '_id' => 'i2', 'label' => 'Week' ),
						),
						'hover_image'       => array( 'id' => 7, 'url' => 'https://example.com/hover.jpg' ),
					)
				),
			)
		);
		$el = $this->outline( $post )[0];
		self::assertSame(
			array(
				'card_title'    => array( 'kind' => 'text', 'value' => 'Bike Hire' ),
				'card_text'     => array( 'kind' => 'html', 'value' => 'From <strong>CHF 30</strong>' ),
				'card_link'     => array( 'kind' => 'url', 'value' => '/hire' ),
				'card_image'    => array( 'kind' => 'image', 'value' => 42 ),
				'items.0.label' => array( 'kind' => 'text', 'value' => 'Day' ),
				'items.1.label' => array( 'kind' => 'text', 'value' => 'Week' ),
			),
			$el['fields']
		);
		self::assertSame( 'items.0.item_link: an address that would run code, left out; ' . AB_MCP_Block_Reader::NOTE_UNMEASURED, $el['note'] );
		$flat = $this->flat( $el );
		foreach ( array( 'alert', 'flat', 'evil.example', 'sk_live', 'zz9', 'hover.jpg', '<script' ) as $never ) {
			self::assertStringNotContainsString( $never, $flat, $never );
		}
	}

	public function testWithElementorLoadedUnregisteredElementsAreLocked(): void {
		$this->loadElementor(
			array(
				'container' => self::type( array() ),
				'heading'   => self::headingType(),
				'e-heading' => self::type( array() ),
			)
		);
		$post = $this->elementorPage(
			123,
			array(
				self::container(
					'bd00001',
					array(
						self::widget( 'bd00002', 'heading', array( 'title' => 'Shown' ) ),
						// A Pro widget while Elementor Pro is inactive.
						self::widget( 'bd00003', 'animated-headline', array( 'before_text' => 'Hidden' ) ),
						self::widget( 'bd00004', 'e-heading', array( 'title' => array( '$$type' => 'escaped-html', 'value' => 'V4 shown' ) ) ),
						self::widget( 'bd00005', 'e-youtube', array( 'source' => array( '$$type' => 'string', 'value' => 'https://youtu.be/x' ) ) ),
					)
				),
				self::container( 'bd00006', array( self::widget( 'bd00007', 'heading', array( 'title' => 'Inside' ) ) ), 'e-grid' ),
			)
		);
		$els = $this->byId( $this->outline( $post ) );
		self::assertSame( 'Shown', $els['bd00002']['fields']['title']['value'] );
		self::assertSame( 'unregistered widget', $els['bd00003']['reason'] );
		self::assertStringContainsString( 'saving the page in Elementor removes it', $els['bd00003']['note'] );
		self::assertSame( 'V4 shown', $els['bd00004']['fields']['title']['value'] );
		self::assertSame( 'unregistered widget', $els['bd00005']['reason'] );
		self::assertSame( 'unknown element type', $els['bd00006']['reason'] );
		self::assertArrayNotHasKey( 'bd00007', $els );
		self::assertStringNotContainsString( 'Hidden', $this->flat( $els ) );
	}

	public function testWithElementorLoadedButUnableToListControlsTheTableTakesOver(): void {
		$throws = new class() {
			public function get_controls( $control_id = null ) {
				throw new \RuntimeException( 'broken add-on' );
			}
		};
		$this->loadElementor(
			array(
				'heading'   => $throws,
				'acme-card' => $throws,
			)
		);
		$post = $this->elementorPage(
			124,
			array(
				self::widget( 'be00001', 'heading', array( 'title' => 'From the table' ) ),
				self::widget( 'be00002', 'acme-card', array( 'card_title' => 'Unknown' ) ),
			)
		);
		$els = $this->byId( $this->outline( $post ) );
		self::assertSame( 'From the table', $els['be00001']['fields']['title']['value'] );
		self::assertSame( 'unknown element type', $els['be00002']['reason'] );
		self::assertStringContainsString( 'could not list the controls', $els['be00002']['note'] );
	}

	/* ---------------------------------------------- the tools around it */

	public function testWpUpdatePostRefusesContentOnAnElementorPage(): void {
		$this->fixturePage( 125, 'klassisch', array( '_elementor_edit_mode' => 'builder' ) );
		$guard = AB_MCP_Builders::content_update_guard( get_post( 125 ) );
		self::assertTrue( $guard['block'], 'post_content is only a copy while Elementor is active.' );
		self::assertSame( 'elementor', $guard['builder'] );
		$out = AB_MCP_Tools_Content::update_post( array( 'id' => 125, 'content' => '<p>neu</p>' ) );
		self::assertSame( 'ab_mcp_builder_content_copy', $out->get_error_code() );
		self::assertStringContainsString( 'Elementor editor in wp-admin', $out->get_error_message() );

		// Elementor inactive: WordPress shows post_content, which is read as such.
		$this->builders( array(), array( 'elementor' ) );
		self::assertSame( 'blocks', AB_MCP_Builders::for_post( get_post( 125 ) )->id() );
		self::assertFalse( AB_MCP_Builders::content_update_guard( get_post( 125 ) )['block'] );
	}
}
