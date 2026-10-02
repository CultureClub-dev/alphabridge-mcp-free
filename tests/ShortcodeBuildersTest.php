<?php
/**
 * The shortcode builders: WPBakery (with the themes built on it), Divi 4,
 * Avada and Flatsome in post_content, Enfold in its layout meta — profiles as
 * data, one adapter per builder.
 *
 * The pages are the vendor examples of the measurement folder of the
 * page-builder plan, word for word (alphabridge-docs: docs/recherche/
 * 2026-09-30-page-builder-messung/quellen/<file>.md, line named at each
 * constant); a page that strings several of them together says so. They are
 * documentation, not measurements: no installation has confirmed them yet,
 * which is why every answer says "not measured".
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Block_Reader;
use AB_MCP_Builder_Adapter_Shortcodes;
use AB_MCP_Builders;
use AB_MCP_Tools_Builders;
use AB_MCP_Tools_Content;
use PHPUnit\Framework\TestCase;

final class ShortcodeBuildersTest extends TestCase {

	use BuilderTestHelpers;

	/* --------------------------------------------- vendor examples, verbatim */

	/** wpbakery.md l. 49. */
	const WPB_HEADING = '[vc_custom_heading text="Über uns" font_container="tag:h2|text_align:left" use_theme_fonts="yes"]';

	/** wpbakery.md l. 57. */
	const WPB_TEXT = '[vc_column_text]<p>Wir sind Ihr Partner für Preise &amp; Angebote.</p>[/vc_column_text]';

	/** wpbakery.md l. 66. */
	const WPB_BUTTON = '[vc_btn title="Angebote ansehen" link="url:https%3A%2F%2Fbeispiel.ch%2Fpreise%2F|title:Preise|target:_blank|rel:nofollow"]';

	/** wpbakery.md l. 74. */
	const WPB_IMAGE = '[vc_single_image image="123" img_size="full" onclick="custom_link" link="https://beispiel.ch/ueber-uns/" img_link_target="_blank"]';

	/** wpbakery.md l. 96. */
	const WPB_QUOTES = '[vc_custom_heading text="Er sagt ``Hallo`` `{`x`}`"]';

	/** wpbakery.md l. 77: the design option every element can carry. */
	const WPB_CSS = 'css=".vc_custom_1727700000000{margin-bottom: 20px !important;}"';

	/** salient-the7.md l. 32–34 (The7). */
	const THE7 = "[dt_highlight color=\"yellow\"]lorem ipsum dolor[/dt_highlight]\n[dt_gap height=\"10\" /]\n[dt_tooltip title=\"tooltip\"]popup content[/dt_tooltip]";

	/** divi-4.md l. 12–18. */
	const DIVI_PAGE = "[et_pb_section fb_built=\"1\" theme_builder_area=\"post_content\" _builder_version=\"…\" _module_preset=\"default\"]\n  [et_pb_row _builder_version=\"…\" theme_builder_area=\"post_content\"]\n    [et_pb_column type=\"4_4\" _builder_version=\"…\"]\n      [et_pb_cta title=\"Über uns\" button_text=\"Mehr erfahren\" …]<p>Text …</p>[/et_pb_cta]\n    [/et_pb_column]\n  [/et_pb_row]\n[/et_pb_section]";

	/** divi-4.md l. 55. */
	const DIVI_TEXT_HEADING = '[et_pb_text _builder_version="…"]<h2>Über uns</h2><p>…</p>[/et_pb_text]';

	/** divi-4.md l. 60. */
	const DIVI_TEXT = '[et_pb_text _builder_version="…" background_color="#E02B20" text_text_color="#FFFFFF"]<p>Unser Angebot für Vereine.</p>[/et_pb_text]';

	/** divi-4.md l. 63. */
	const DIVI_BUTTON = '[et_pb_button button_text="Preise ansehen" button_url="https://example.ch/preise/" url_new_window="on" _builder_version="…"][/et_pb_button]';

	/** divi-4.md l. 68. */
	const DIVI_IMAGE = '[et_pb_image src="https://example.ch/wp-content/uploads/2026/09/team.jpg" alt="Unser Team" title_text="Team" url="https://example.ch/team/" url_new_window="off"][/et_pb_image]';

	/** avada.md l. 57–62. */
	const AVADA_PAGE = "[fusion_builder_container …][fusion_builder_row][fusion_builder_column …]\n[fusion_title …]Über uns[/fusion_title]\n[fusion_text …]<p>Preise &amp; Angebote …</p>[/fusion_text]\n[fusion_button link=\"https://example.com/kontakt/\" target=\"_self\" …]Kontakt[/fusion_button]\n[fusion_imageframe …]…[/fusion_imageframe]\n[/fusion_builder_column][/fusion_builder_row][/fusion_builder_container]";

	/** avada.md l. 73 (third-party plugin [42]). */
	const AVADA_TEXT = '[fusion_text]Hello[/fusion_text]';

	/** flatsome.md l. 38. */
	const FS_TITLE = '[title style="center" text="Über uns" tag_name="h2"]';

	/** flatsome.md l. 40. */
	const FS_COL = '[col span="12" span__sm="12"]<p>Wir sind …</p>[/col]';

	/** flatsome.md l. 42. */
	const FS_BUTTON = '[button text="Preise & Angebote" link="https://example.ch/preise/" target="_blank" radius="99"]';

	/** flatsome.md l. 43. */
	const FS_IMAGE = '[ux_image id="123"]';

	/** flatsome.md l. 44. */
	const FS_BANNER = '[ux_banner bg="…" height="400px"]<h3>…</h3>[/ux_banner]';

	/** flatsome.md l. 46. */
	const FS_ACCORDION = '[accordion-item title="…"]…[/accordion-item]';

	/** flatsome.md l. 46. */
	const FS_FOLLOW = '[follow facebook="https://…"]';

	/** flatsome.md l. 28. */
	const FS_BLOCK = '[block id="size-guide"]';

	/** enfold.md l. 49 (vendor documentation [19]). */
	const ENF_HEADING_DOC = "[av_heading tag='h3' padding='10' heading='Hello' color='' style='' custom_font='' size='' subheading_active='' … av_uid='av-1g3l495'][/av_heading]";

	/** enfold.md l. 50. */
	const ENF_HEADING = "[av_heading heading='Über uns' tag='h2' style='blockquote modern-quote' subheading_active='subheading_below' link='' link_target='' av_uid='av-mb1x2y']Seit 1999 in Bern[/av_heading]";

	/** enfold.md l. 55: opening tag, line break, text, line break, closing tag. */
	const ENF_TEXT = "[av_textblock size='' font_color='' color='' av_uid='av-mb1x2z' custom_class='' admin_preview_bg='']\n<p>Preise &amp; Angebote für 2027</p>\n[/av_textblock]";

	/** enfold.md l. 58 (vendor documentation [20]). */
	const ENF_BUTTON_DOC = "[av_button label='Click me' link='manually,http://' link_target='' size='small' position='center' icon_select='yes' icon='ue800' font='entypo-fontello' color='theme-color' custom_bg='#444444' custom_font='#ffffff' av_uid='av-5obiu2i']";

	/** enfold.md l. 60. */
	const ENF_BUTTON = "[av_button label='Offerte anfragen' link='page,42' link_target='' size='large' position='center' av_uid='av-mb1x30']";

	/** enfold.md l. 63 (vendor documentation [21]). */
	const ENF_IMAGE_DOC = "[av_image src='http://…/placeholder.jpg' align='center' styling='' hover='' link='' target='' caption='' font_size='' appearance='' … av_uid='av-5bzohe'][/av_image]";

	/** enfold.md l. 65. */
	const ENF_IMAGE = "[av_image src='https://beispiel.ch/wp-content/uploads/team.jpg' attachment='123' attachment_size='full' align='center' link='' target='' caption='' av_uid='av-mb1x31'][/av_image]";

	/** Note on every element read with a profile entry. */
	const VENDOR = AB_MCP_Builder_Adapter_Shortcodes::NOTE_VENDOR;

	protected function setUp(): void {
		ab_test_reset();
		unset( $GLOBALS['shortcode_tags'] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['shortcode_tags'] );
	}

	/* ------------------------------------------------------------ helpers */

	/** The outline one builder's reader makes of a post. */
	private function outline( string $builder, object $post, array $o = array() ): array {
		$adapter = AB_MCP_Builders::adapter_for_builder( $builder );
		self::assertInstanceOf( AB_MCP_Builder_Adapter_Shortcodes::class, $adapter, $builder );
		return $adapter->outline( $post, $o );
	}

	/** The outline of a text in post_content. */
	private function read( string $builder, string $content, array $o = array() ): array {
		return $this->outline( $builder, $this->page( 900, $content ), $o );
	}

	/** Elements by id. */
	private function byId( array $elements ): array {
		$out = array();
		foreach ( $elements as $e ) {
			$out[ $e['id'] ] = $e;
		}
		return $out;
	}

	/** One field's value. */
	private static function field( array $element, string $name ) {
		return $element['fields'][ $name ]['value'] ?? null;
	}

	/** An Enfold page: the layout in the meta, a stale copy in post_content. */
	private function enfoldPage( int $id, string $layout, string $copy = '[av_textblock]Alte Kopie[/av_textblock]' ): object {
		return $this->page(
			$id,
			$copy,
			array(
				'_aviaLayoutBuilder_active'    => 'active',
				'_aviaLayoutBuilderCleanData' => $layout,
			)
		);
	}

	/* ------------------------------------------------------- registration */

	public function testOneReaderPerShortcodeBuilder(): void {
		foreach ( array( 'wpbakery', 'divi4', 'avada', 'flatsome', 'enfold' ) as $builder ) {
			$adapter = AB_MCP_Builders::adapter_for_builder( $builder );
			self::assertInstanceOf( AB_MCP_Builder_Adapter_Shortcodes::class, $adapter, $builder );
			self::assertSame( $builder, $adapter->id() );
			self::assertSame( array( $builder ), $adapter->builders() );
			self::assertNotNull( AB_MCP_Builders::signature( $builder ), $builder . ' has a signature.' );
			self::assertSame( AB_MCP_Builders::signature( $builder )['name'], $adapter->name() );
			self::assertFalse( $adapter->verified(), $builder . ': read from vendor documentation, not measured.' );
		}
		self::assertSame( 'meta:_aviaLayoutBuilderCleanData', AB_MCP_Builders::adapter_for_builder( 'enfold' )->layout_source( $this->enfoldPage( 901, '[av_textblock]x[/av_textblock]' ) )['loc'] );
	}

	public function testEveryProfileIsUnmeasuredAndNamesItsSource(): void {
		$profiles = AB_MCP_Builder_Adapter_Shortcodes::profiles();
		self::assertSame( array( 'avada', 'divi4', 'enfold', 'flatsome', 'wpbakery' ), array_column( $profiles, 'builder' ) );
		$files = array(
			'avada'    => 'quellen/avada.md',
			'divi4'    => 'quellen/divi-4.md',
			'enfold'   => 'quellen/enfold.md',
			'flatsome' => 'quellen/flatsome.md',
			'wpbakery' => 'quellen/wpbakery.md',
		);
		foreach ( $profiles as $p ) {
			self::assertFalse( $p['verified'], $p['builder'] );
			self::assertStringStartsWith( $files[ $p['builder'] ], $p['source'] );
			self::assertNotEmpty( $p['family'], $p['builder'] );
			foreach ( $p['tags'] as $tag => $spec ) {
				self::assertFalse( $spec['verified'], $p['builder'] . ' ' . $tag );
			}
		}
		// The rule for the profile files, as for signatures.php: nothing without
		// a source. Every group of tag entries (lines between blank lines)
		// opens with a comment that names it, or the entry carries its own.
		foreach ( glob( dirname( __DIR__ ) . '/includes/builders/profiles/shortcodes-*.php' ) as $file ) {
			$checked = 0;
			$sourced = false;
			foreach ( file( $file ) as $n => $line ) {
				if ( '' === trim( $line ) ) {
					$sourced = false;
					continue;
				}
				if ( 1 === preg_match( '~^\s*//~', $line ) ) {
					$sourced = true;
					continue;
				}
				if ( 1 !== preg_match( "~^\t\t'[a-z0-9_-]+'\s+=> array\(~", $line ) ) {
					continue;
				}
				++$checked;
				self::assertTrue( $sourced || false !== strpos( $line, '//' ), basename( $file ) . ' line ' . ( $n + 1 ) . ' has no source: ' . trim( $line ) );
			}
			self::assertGreaterThan( 5, $checked, basename( $file ) . ': the check saw the tag entries.' );
		}
	}

	public function testRecognisedPagesAreRead(): void {
		$this->builders( array( 'wpbakery', 'divi4', 'avada', 'flatsome', 'enfold' ) );
		$pages = array(
			'wpbakery' => $this->page( 902, '[vc_row][vc_column]' . self::WPB_TEXT . '[/vc_column][/vc_row]' ),
			'divi4'    => $this->page( 903, self::DIVI_PAGE ),
			'avada'    => $this->page( 904, self::AVADA_PAGE ),
			'flatsome' => $this->page( 905, '[section][row]' . self::FS_COL . '[/row][/section]' ),
			'enfold'   => $this->enfoldPage( 906, self::ENF_TEXT ),
		);
		foreach ( $pages as $builder => $post ) {
			$primary = AB_MCP_Builders::primary( $post );
			self::assertSame( $builder, $primary['id'] );
			self::assertSame( 'read', $primary['support'], $builder );
			self::assertSame( $builder, AB_MCP_Builders::for_post( $post )->id() );
		}
	}

	/* ----------------------------------------------------------- WPBakery */

	public function testWpbakeryVendorExamples(): void {
		// The examples of wpbakery.md, one after the other in the structure of a) [6].
		$page = '[vc_row][vc_column]' . self::WPB_HEADING . self::WPB_TEXT . self::WPB_BUTTON . self::WPB_IMAGE . self::WPB_QUOTES . '[/vc_column][/vc_row]';
		self::assertSame(
			array(
				array( 'id' => 's0', 'type' => 'vc_row', 'parent' => null, 'depth' => 0 ),
				array( 'id' => 's0.0', 'type' => 'vc_column', 'parent' => 's0', 'depth' => 1 ),
				array(
					'id'     => 's0.0.0',
					'type'   => 'vc_custom_heading',
					'parent' => 's0.0',
					'depth'  => 2,
					'fields' => array( 'text' => array( 'kind' => 'heading', 'value' => 'Über uns' ) ),
					'note'   => self::VENDOR,
				),
				array(
					'id'     => 's0.0.1',
					'type'   => 'vc_column_text',
					'parent' => 's0.0',
					'depth'  => 2,
					'fields' => array( 'text' => array( 'kind' => 'html', 'value' => 'Wir sind Ihr Partner für Preise &amp; Angebote.' ) ),
					'note'   => self::VENDOR,
				),
				array(
					'id'     => 's0.0.2',
					'type'   => 'vc_btn',
					'parent' => 's0.0',
					'depth'  => 2,
					'fields' => array(
						'text' => array( 'kind' => 'text', 'value' => 'Angebote ansehen' ),
						'url'  => array( 'kind' => 'url', 'value' => 'https://beispiel.ch/preise/' ),
					),
					'note'   => self::VENDOR,
				),
				array(
					'id'     => 's0.0.3',
					'type'   => 'vc_single_image',
					'parent' => 's0.0',
					'depth'  => 2,
					'fields' => array(
						'image' => array( 'kind' => 'image', 'value' => 123 ),
						'link'  => array( 'kind' => 'url', 'value' => 'https://beispiel.ch/ueber-uns/' ),
					),
					'note'   => self::VENDOR,
				),
				array(
					'id'     => 's0.0.4',
					'type'   => 'vc_custom_heading',
					'parent' => 's0.0',
					'depth'  => 2,
					'fields' => array( 'text' => array( 'kind' => 'heading', 'value' => 'Er sagt "Hallo" [x]' ) ),
					'note'   => self::VENDOR,
				),
			),
			$this->read( 'wpbakery', $page )
		);
	}

	public function testWpbakeryCodeStylingAndLockedElementsAreNeverShown(): void {
		// Raw HTML / JS hold base64 (wpbakery.md d) [16]); the map embed a "#E-8_" value ([47]); the button
		// click code custom_onclick_code (g) [45]); every element may carry the design option css (l. 77).
		$page = '[vc_row ' . self::WPB_CSS . '][vc_column el_class="my-class"]'
			. '[vc_raw_html]JTNDc2NyaXB0JTNFYWxlcnQoMSklM0MlMkZzY3JpcHQlM0U=[/vc_raw_html]'
			. '[vc_raw_js]JTNDc2NyaXB0JTNFdHJhY2soKSUzQyUyRnNjcmlwdCUzRQ==[/vc_raw_js]'
			. '[vc_gmaps link="#E-8_JTNDaWZyYW1lJTIwc3JjJTNEJTIyaHR0cHMlM0ElMkYlMkZtYXBzJTIyJTNF"]'
			. '[vc_btn title="Jetzt buchen" custom_onclick="true" custom_onclick_code="dataLayer.push({event:\'buy\'})" ' . self::WPB_CSS . ']'
			. '[/vc_column][/vc_row]';
		$out  = $this->read( 'wpbakery', $page );
		$flat = $this->flat( $out );
		foreach ( array( 'JTNDc2Nya', 'JTNDaWZyYW1l', 'dataLayer', 'vc_custom_1727700000000', 'margin-bottom', 'my-class' ) as $never ) {
			self::assertStringNotContainsString( $never, $flat );
		}
		$e = $this->byId( $out );
		self::assertSame( array( 'locked' => true, 'reason' => 'code element' ), array_intersect_key( $e['s0.0.0'], array_flip( array( 'locked', 'reason' ) ) ) );
		self::assertSame( 'vc_raw_js', $e['s0.0.1']['type'] );
		self::assertTrue( $e['s0.0.1']['locked'] );
		self::assertSame( 'embed code', $e['s0.0.2']['reason'] );
		self::assertArrayNotHasKey( 'fields', $e['s0.0.0'] );
		self::assertSame( 'Jetzt buchen', self::field( $e['s0.0.3'], 'text' ) );
		self::assertStringContainsString( 'holds code in custom_onclick_code, not shown', $e['s0.0.3']['note'] );

		$quiet = $this->read( 'wpbakery', $page, array( 'include_locked' => false ) );
		self::assertSame( array( 's0', 's0.0', 's0.0.3' ), array_column( $quiet, 'id' ), 'Locked elements left out on request.' );
	}

	public function testWpbakeryHeadingFromThePostTitleAndEncodedValues(): void {
		// source="post_title": the text comes from the post title (wpbakery.md c) [45]).
		$out = $this->read( 'wpbakery', '[vc_custom_heading source="post_title" text="Ignoriert"][vc_btn title="#E-8_JTNDYiUzRWhpJTNDJTJGYiUzRQ=="]' );
		self::assertArrayNotHasKey( 'fields', $out[0] );
		self::assertStringContainsString( 'text: the text comes from the post title', $out[0]['note'] );
		self::assertArrayNotHasKey( 'fields', $out[1] );
		self::assertStringContainsString( 'text: an encoded value, not shown', $out[1]['note'] );
	}

	public function testWpbakeryAddressesThatWouldRunCodeAreLeftOut(): void {
		$out = $this->read( 'wpbakery', '[vc_btn title="X" link="url:javascript%3Aalert(1)|title:x"][vc_single_image image="7" custom_src="data:text/html;base64,PHNjcmlwdD4="]' );
		self::assertSame( array( 'text' ), array_keys( $out[0]['fields'] ) );
		self::assertStringContainsString( 'url: an address that would run code, left out', $out[0]['note'] );
		self::assertSame( array( 'image' ), array_keys( $out[1]['fields'] ) );
		self::assertStringContainsString( 'src: an address that would run code, left out', $out[1]['note'] );
		self::assertStringNotContainsString( 'javascript', $this->flat( $out ) );
	}

	public function testWpbakeryThemeElementsAreReadAsTheFamilysOwn(): void {
		// The7 inside a WPBakery column (salient-the7.md c) [26]): type and visible text, no attribute.
		$out = $this->read( 'wpbakery', "[vc_row][vc_column]\n" . self::THE7 . "\n[dt_code]<script>x()</script>[/dt_code][/vc_column][/vc_row]" );
		$e   = $this->byId( $out );
		self::assertSame( 'dt_highlight', $e['s0.0.0']['type'] );
		self::assertSame( array( 'text' => array( 'kind' => 'text', 'value' => 'lorem ipsum dolor' ) ), $e['s0.0.0']['fields'] );
		self::assertSame( AB_MCP_Block_Reader::NOTE_UNVERIFIED, $e['s0.0.0']['note'] );
		self::assertSame( 'dt_gap', $e['s0.0.1']['type'] );
		self::assertArrayNotHasKey( 'fields', $e['s0.0.1'] );
		self::assertSame( 'popup content', self::field( $e['s0.0.2'], 'text' ) );
		self::assertStringNotContainsString( 'tooltip', $this->flat( $e['s0.0.2']['fields'] ), 'The title attribute is not read.' );
		self::assertSame( 'code element', $e['s0.0.3']['reason'] );
		self::assertStringNotContainsString( 'x()', $this->flat( $out ) );
	}

	public function testTextAContainerHoldsIsListedAsText(): void {
		$out = $this->read( 'wpbakery', "Intro <b>vor</b> der Reihe\n[vc_row]&nbsp;[vc_column]<p>Lose im Text</p>" . self::WPB_TEXT . "\n\n[/vc_column][/vc_row]" );
		// "&nbsp;" is a text run of the reader (and counts in the path), but no element: nothing visible.
		self::assertSame( array( 's0', 's1', 's1.1', 's1.1.0', 's1.1.1' ), array_column( $out, 'id' ), 'White space and &nbsp; are no element.' );
		self::assertSame( '#text', $out[0]['type'] );
		self::assertSame( 'Intro <b>vor</b> der Reihe', self::field( $out[0], 'text' ) );
		self::assertSame( self::VENDOR, $out[0]['note'], 'Where text runs stand follows from the profile, which is not measured.' );
		self::assertSame( array( '#text', 's1.1' ), array( $out[3]['type'], $out[3]['parent'] ) );
		self::assertSame( 'Lose im Text', self::field( $out[3], 'text' ) );
		self::assertSame( 'vc_column_text', $out[4]['type'] );
	}

	public function testNotesSayHowAnElementWasReadNotWhetherItCanBeChanged(): void {
		// A writer changes what a profile entry describes and the text runs
		// between the shortcodes, with the warning that the profile is not measured;
		// only an element no entry describes is never written. So only that
		// one may be called read only.
		$out  = $this->read( 'wpbakery', "Intro\n[vc_row][vc_column]" . self::WPB_HEADING . self::THE7 . '[/vc_column][/vc_row]' );
		$type = array_column( $out, null, 'type' );
		foreach ( array( '#text', 'vc_custom_heading' ) as $written ) {
			self::assertSame( self::VENDOR, $type[ $written ]['note'], $written );
			self::assertStringNotContainsString( 'read only', $type[ $written ]['note'], $written );
			self::assertStringContainsString( AB_MCP_Block_Reader::NOTE_UNMEASURED, $type[ $written ]['note'], $written . ': the outline tool counts it as unmeasured by this.' );
		}
		self::assertSame( AB_MCP_Block_Reader::NOTE_UNVERIFIED, $type['dt_highlight']['note'] );
		self::assertStringContainsString( 'read only', $type['dt_highlight']['note'], 'Not described by the profile: nothing writes it.' );
	}

	public function testShortcodesOfOtherPluginsCountWhenTheSiteRegistersThem(): void {
		// As WordPress: "[1]" and "[inkl. MwSt.]" are text unless a shortcode of that name exists.
		$GLOBALS['shortcode_tags'] = array(
			'contact-form-7' => '__return_empty_string',
			'highlight'      => '__return_empty_string',
		);
		$out = $this->read( 'wpbakery', '[vc_column_text]<p>Preis [1] [inkl. MwSt.] im [highlight]Angebot[/highlight]</p>[contact-form-7 id="5" title="Kontakt"][/vc_column_text][gallery ids="1,2"]' );
		$e   = $this->byId( $out );
		self::assertSame( 'Preis [1] [inkl. MwSt.] im', self::field( $e['s0'], 'text' ) );
		self::assertStringContainsString( 'text: holds shortcodes, listed as its children', $e['s0']['note'] );
		self::assertSame( array( 'highlight', 'Angebot' ), array( $e['s0.1']['type'], self::field( $e['s0.1'], 'text' ) ) );
		self::assertSame( AB_MCP_Block_Reader::NOTE_UNVERIFIED, $e['s0.1']['note'] );
		self::assertSame( array( 'contact-form-7', true, 'shortcode' ), array( $e['s0.3']['type'], $e['s0.3']['locked'], $e['s0.3']['reason'] ) );
		self::assertSame( array( '#text', '[gallery ids="1,2"]' ), array( $e['s1']['type'], self::field( $e['s1'], 'text' ) ), 'An unregistered [gallery] is text on the page, as WordPress shows it.' );
		self::assertStringNotContainsString( 'Kontakt', $this->flat( $out ), 'No attribute of another plugin is read.' );

		unset( $GLOBALS['shortcode_tags'] );
		$plain = $this->read( 'wpbakery', '[vc_column_text]<p>Preis [1]</p>[contact-form-7 id="5"][/vc_column_text]' );
		self::assertCount( 1, $plain, 'Without the registration the form shortcode is text of the block.' );
		self::assertSame( 'Preis [1] [contact-form-7 id="5"]', self::field( $plain[0], 'text' ) );
	}

	public function testFlatsomeTagsMeanNothingOnAWpbakeryPage(): void {
		$out = $this->read( 'wpbakery', '[vc_column_text]' . self::FS_BUTTON . '[/vc_column_text]' );
		self::assertCount( 1, $out );
		self::assertSame( '[button text="Preise & Angebote" link="https://example.ch/preise/" target="_blank" radius="99"]', self::field( $out[0], 'text' ) );
	}

	/* -------------------------------------------------------------- Divi 4 */

	public function testDiviVendorStructure(): void {
		$out = $this->read( 'divi4', self::DIVI_PAGE );
		self::assertSame( array( 'et_pb_section', 'et_pb_row', 'et_pb_column', 'et_pb_cta' ), array_column( $out, 'type' ) );
		self::assertSame( array( 's0', 's0.0', 's0.0.0', 's0.0.0.0' ), array_column( $out, 'id' ) );
		self::assertSame( array( null, 's0', 's0.0', 's0.0.0' ), array_column( $out, 'parent' ) );
		self::assertSame(
			array(
				'title'       => array( 'kind' => 'heading', 'value' => 'Über uns' ),
				'text'        => array( 'kind' => 'html', 'value' => 'Text …' ),
				'button_text' => array( 'kind' => 'text', 'value' => 'Mehr erfahren' ),
			),
			$out[3]['fields']
		);
		self::assertSame( self::VENDOR, $out[3]['note'] );
	}

	public function testDiviModules(): void {
		$out = $this->read( 'divi4', self::DIVI_TEXT_HEADING . self::DIVI_TEXT . self::DIVI_BUTTON . self::DIVI_IMAGE );
		self::assertSame( 'Über uns …', self::field( $out[0], 'text' ) );
		self::assertSame( 'Unser Angebot für Vereine.', self::field( $out[1], 'text' ) );
		self::assertSame(
			array(
				'text' => array( 'kind' => 'text', 'value' => 'Preise ansehen' ),
				'url'  => array( 'kind' => 'url', 'value' => 'https://example.ch/preise/' ),
			),
			$out[2]['fields']
		);
		self::assertSame(
			array(
				'image' => array( 'kind' => 'image', 'value' => 'https://example.ch/wp-content/uploads/2026/09/team.jpg' ),
				'alt'   => array( 'kind' => 'text', 'value' => 'Unser Team' ),
				'title' => array( 'kind' => 'text', 'value' => 'Team' ),
			),
			$out[3]['fields'],
			'The image link attribute is open in the source and not read.'
		);
	}

	public function testDiviPercentSequencesAndDynamicContent(): void {
		$out = $this->read( 'divi4', '[et_pb_button button_text="Jetzt %22gratis%22 %91testen%93" button_url="https://example.ch/"][/et_pb_button][et_pb_cta title="@ET-DC@eyJkeW5hbWljIjp0cnVlfQ==@" button_text="Los"]<p>x</p>[/et_pb_cta]' );
		self::assertSame( 'Jetzt "gratis" [testen]', self::field( $out[0], 'text' ) );
		self::assertArrayNotHasKey( 'title', $out[1]['fields'] );
		self::assertStringContainsString( 'title: dynamic content, filled in when the page is shown', $out[1]['note'] );
		self::assertStringNotContainsString( 'ET-DC', $this->flat( $out ) );
	}

	public function testDiviCodeAndFormsAreLocked(): void {
		$out  = $this->read( 'divi4', '[et_pb_column type="4_4"][et_pb_code]%3Cscript%3Etrack()%3C/script%3E[/et_pb_code][et_pb_contact_form email="chef@example.ch" title="Kontakt"][et_pb_contact_field field_title="Name"][/et_pb_contact_field][/et_pb_contact_form][et_pb_signup provider="mailchimp" mailchimp_list="abc|123"][/et_pb_signup][/et_pb_column]' );
		$flat = $this->flat( $out );
		self::assertSame( array( 'et_pb_column', 'et_pb_code', 'et_pb_contact_form', 'et_pb_signup' ), array_column( $out, 'type' ), 'The form field inside a locked form is not listed.' );
		self::assertSame( array( 'code element', 'form', 'form' ), array_column( array_slice( $out, 1 ), 'reason' ) );
		foreach ( array( 'track', 'chef@example.ch', 'Name', 'abc|123' ) as $never ) {
			self::assertStringNotContainsString( $never, $flat );
		}
	}

	public function testDiviGlobalModule(): void {
		// The linking attribute is prior knowledge in divi-4.md b); synced text lives in the Divi Library.
		$out = $this->read( 'divi4', '[et_pb_text global_module="1234"]<p>Öffnungszeiten</p>[/et_pb_text]' );
		self::assertTrue( $out[0]['global'] );
		self::assertSame( 'Öffnungszeiten', self::field( $out[0], 'text' ) );
		self::assertStringContainsString( 'global module: its synced fields come from Divi Library item 1234', $out[0]['note'] );
	}

	/* --------------------------------------------------------------- Avada */

	public function testAvadaVendorExample(): void {
		$out = $this->read( 'avada', self::AVADA_PAGE );
		self::assertSame( array( 'fusion_builder_container', 'fusion_builder_row', 'fusion_builder_column', 'fusion_title', 'fusion_text', 'fusion_button', 'fusion_imageframe' ), array_column( $out, 'type' ) );
		self::assertSame( array( 'kind' => 'heading', 'value' => 'Über uns' ), $out[3]['fields']['text'] );
		self::assertSame( 'Preise &amp; Angebote …', self::field( $out[4], 'text' ) );
		self::assertSame(
			array(
				'text' => array( 'kind' => 'text', 'value' => 'Kontakt' ),
				'url'  => array( 'kind' => 'url', 'value' => 'https://example.com/kontakt/' ),
			),
			$out[5]['fields']
		);
		self::assertArrayNotHasKey( 'fields', $out[6], 'Where the image frame keeps its image is open.' );
		self::assertStringContainsString( 'not documented yet', $out[6]['note'] );
		self::assertSame( 'Hello', self::field( $this->read( 'avada', self::AVADA_TEXT )[0], 'text' ) );
	}

	public function testAvadaPlaceholdersStayAndAreNoted(): void {
		// Inline Dynamic Data (avada.md d) [17]): braces are not decoded to brackets.
		$out = $this->read( 'avada', '[fusion_text]<p>Willkommen bei {post_title}</p>[/fusion_text][fusion_title]Kategorie {post_terms,type:category,separator:|}[/fusion_title][fusion_text]<p>Mengen {x} bleiben</p>[/fusion_text]' );
		self::assertSame( 'Willkommen bei {post_title}', self::field( $out[0], 'text' ) );
		self::assertStringContainsString( 'text: holds placeholders that are filled in when the page is shown', $out[0]['note'] );
		self::assertSame( 'Kategorie {post_terms,type:category,separator:|}', self::field( $out[1], 'text' ) );
		self::assertStringContainsString( 'placeholders', $out[1]['note'] );
		self::assertStringNotContainsString( 'placeholders', $out[2]['note'] );
	}

	public function testAvadaCodeBlockIsLocked(): void {
		$out = $this->read( 'avada', '[fusion_builder_column][fusion_code]PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==[/fusion_code][fusion_global id="88"][/fusion_builder_column]' );
		self::assertSame( 'code element', $out[1]['reason'] );
		self::assertStringNotContainsString( 'PHNjcmlwdD', $this->flat( $out ) );
		self::assertSame( array( 'global element', true ), array( $out[2]['reason'], $out[2]['global'] ) );
		self::assertSame( 'its content is Avada Library item 88', $out[2]['note'] );
	}

	/* ------------------------------------------------------------ Flatsome */

	public function testFlatsomeVendorExamples(): void {
		// The examples of flatsome.md c), in the structure of l. 34.
		$page = '[section][row]' . self::FS_COL . '[col span="6" span__sm="12"]' . self::FS_TITLE . self::FS_BUTTON . self::FS_IMAGE . '[/col][/row][/section]'
			. self::FS_BANNER . self::FS_ACCORDION . self::FS_FOLLOW . self::FS_BLOCK;
		$out  = $this->read( 'flatsome', $page );
		self::assertSame(
			array( 'section', 'row', 'col', 'col', 'title', 'button', 'ux_image', 'ux_banner', 'accordion-item', 'follow', 'block' ),
			array_column( $out, 'type' )
		);
		$e = $this->byId( $out );
		self::assertArrayNotHasKey( 'fields', $e['s0'] );
		self::assertSame( 'Wir sind …', self::field( $e['s0.0.0'], 'text' ) );
		self::assertArrayNotHasKey( 'fields', $e['s0.0.1'], 'A column holding only elements has no text.' );
		self::assertSame( array( 'kind' => 'heading', 'value' => 'Über uns' ), $e['s0.0.1.0']['fields']['text'] );
		self::assertSame(
			array(
				'text' => array( 'kind' => 'text', 'value' => 'Preise & Angebote' ),
				'url'  => array( 'kind' => 'url', 'value' => 'https://example.ch/preise/' ),
			),
			$e['s0.0.1.1']['fields']
		);
		self::assertArrayNotHasKey( 'fields', $e['s0.0.1.2'], 'ux_image attributes are open in the source.' );
		self::assertSame( array( 'text' => '…', 'image' => '…' ), array_map( static fn( $f ) => $f['value'], $e['s1']['fields'] ) );
		self::assertSame( array( 'title' => '…', 'text' => '…' ), array_map( static fn( $f ) => $f['value'], $e['s2']['fields'] ) );
		self::assertSame( 'https://…', self::field( $e['s3'], 'facebook' ) );
		self::assertSame( array( true, 'global element', true, 'its content is UX Block size-guide' ), array( $e['s4']['locked'], $e['s4']['reason'], $e['s4']['global'], $e['s4']['note'] ) );
	}

	public function testFlatsomeTextBesideElementsAndTheHtmlElement(): void {
		$out = $this->read( 'flatsome', '[col span="6"]<p>Vorher</p>[button text="Mehr" link="/kontakt"]<p>Nachher</p>[/col][ux_html]<script>x()</script>[/ux_html][section label="Intro" bg="77"][/section]' );
		self::assertSame( 'Vorher Nachher', self::field( $out[0], 'text' ) );
		self::assertStringContainsString( 'text: holds shortcodes, listed as its children', $out[0]['note'] );
		self::assertSame( 'html element', $out[2]['reason'] );
		self::assertSame( array( 'image' => array( 'kind' => 'image', 'value' => 77 ) ), $out[3]['fields'] );
		self::assertStringNotContainsString( 'Intro', $this->flat( $out ), 'The builder label is not page text.' );
		self::assertStringNotContainsString( 'x()', $this->flat( $out ) );
	}

	/* -------------------------------------------------------------- Enfold */

	public function testEnfoldReadsTheLayoutMetaNotTheCopy(): void {
		$layout = "[av_one_full first]\n" . self::ENF_HEADING . "\n\n" . self::ENF_TEXT . "\n\n" . self::ENF_BUTTON . "\n\n" . self::ENF_IMAGE . "\n\n" . self::ENF_BUTTON_DOC . "\n\n[/av_one_full]";
		$out    = $this->outline( 'enfold', $this->enfoldPage( 910, $layout ) );
		self::assertStringNotContainsString( 'Alte Kopie', $this->flat( $out ), 'post_content is only a copy.' );
		self::assertSame( array( 's0', 'av-mb1x2y', 'av-mb1x2z', 'av-mb1x30', 'av-mb1x31', 'av-5obiu2i' ), array_column( $out, 'id' ), 'av_uid is the element id.' );
		self::assertSame( array( null, 's0', 's0', 's0', 's0', 's0' ), array_column( $out, 'parent' ) );
		self::assertSame(
			array(
				'heading'    => array( 'kind' => 'heading', 'value' => 'Über uns' ),
				'subheading' => array( 'kind' => 'html', 'value' => 'Seit 1999 in Bern' ),
			),
			$out[1]['fields'],
			'link=\'\' is no link.'
		);
		self::assertSame( 'Preise &amp; Angebote für 2027', self::field( $out[2], 'text' ) );
		self::assertSame(
			array(
				'text' => array( 'kind' => 'text', 'value' => 'Offerte anfragen' ),
				'url'  => array( 'kind' => 'url', 'value' => 'page,42' ),
			),
			$out[3]['fields']
		);
		self::assertStringContainsString( 'url: a link to page #42 of this site, stored as type,id', $out[3]['note'] );
		self::assertSame(
			array(
				'image' => array( 'kind' => 'image', 'value' => 123 ),
				'src'   => array( 'kind' => 'url', 'value' => 'https://beispiel.ch/wp-content/uploads/team.jpg' ),
			),
			$out[4]['fields']
		);
		self::assertSame( array( 'text' => array( 'kind' => 'text', 'value' => 'Click me' ) ), $out[5]['fields'], '"manually,http://" is no link.' );
	}

	public function testEnfoldDocumentationExamples(): void {
		$out = $this->outline( 'enfold', $this->enfoldPage( 911, self::ENF_HEADING_DOC . self::ENF_IMAGE_DOC . "[av_textblock size='' font_color='' color='' av-medium-font-size='' av-small-font-size='' av-mini-font-size='' av_uid='av-jq3hw' custom_class='' admin_preview_bg='']" ) );
		self::assertSame( array( 'av-1g3l495', 'av-5bzohe', 'av-jq3hw' ), array_column( $out, 'id' ) );
		self::assertSame( array( 'heading' => array( 'kind' => 'heading', 'value' => 'Hello' ), 'subheading' => array( 'kind' => 'html', 'value' => '' ) ), $out[0]['fields'] );
		self::assertSame( 'http://…/placeholder.jpg', self::field( $out[1], 'src' ) );
		self::assertArrayNotHasKey( 'fields', $out[2], 'enfold.md l. 54 quotes the opening tag alone: no content.' );
	}

	public function testEnfoldLinkFormats(): void {
		// enfold.md c) "Link-Formate" [17][20]: own addresses after "manually,", "lightbox", type,id.
		$out = $this->outline(
			'enfold',
			$this->enfoldPage(
				916,
				"[av_button label='Kontakt' link='manually,https://beispiel.ch/kontakt/' av_uid='av-l1']"
				. "[av_button label='Anrufen' link='manually,tel:+41311234567' av_uid='av-l2']"
				. "[av_button label='Böse' link='manually,javascript:alert(1)' av_uid='av-l3']"
				. "[av_image src='https://beispiel.ch/a.jpg' attachment='9' link='lightbox' av_uid='av-l4'][/av_image]"
			)
		);
		self::assertSame( 'https://beispiel.ch/kontakt/', self::field( $out[0], 'url' ) );
		self::assertSame( 'tel:+41311234567', self::field( $out[1], 'url' ) );
		self::assertArrayNotHasKey( 'url', $out[2]['fields'] );
		self::assertStringContainsString( 'url: an address that would run code, left out', $out[2]['note'] );
		self::assertArrayNotHasKey( 'link', $out[3]['fields'] );
		self::assertStringContainsString( 'link: opens the image in a lightbox', $out[3]['note'] );
		self::assertStringNotContainsString( 'manually', $this->flat( $out ) );
	}

	public function testEnfoldApostropheIsShownAsStored(): void {
		// enfold.md d): the builder stores "Peter's Café" in an attribute as "Peter’s Café".
		$out = $this->outline( 'enfold', $this->enfoldPage( 912, "[av_heading heading='Peter’s Café' tag='h2' av_uid='av-a1'][/av_heading]" ) );
		self::assertSame( 'Peter’s Café', self::field( $out[0], 'heading' ) );
	}

	public function testEnfoldWithoutLayoutMetaReadsPostContentAndSaysSo(): void {
		$post = $this->page( 913, self::ENF_TEXT, array( '_aviaLayoutBuilder_active' => 'active' ) );
		$out  = $this->outline( 'enfold', $post );
		self::assertSame( 'Preise &amp; Angebote für 2027', self::field( $out[0], 'text' ) );
		self::assertStringContainsString( 'read from post_content: _aviaLayoutBuilderCleanData holds no shortcodes', $out[0]['note'] );
		self::assertSame( 'post_content', AB_MCP_Builders::adapter_for_builder( 'enfold' )->tree( $post )['loc'] );
	}

	public function testEnfoldDuplicatedUidsFallBackToPaths(): void {
		$out = $this->outline( 'enfold', $this->enfoldPage( 914, "[av_textblock av_uid='av-dup']A[/av_textblock][av_textblock av_uid='av-dup']B[/av_textblock][av_textblock av_uid='s9']C[/av_textblock]" ) );
		self::assertSame( array( 's0', 's1', 's2' ), array_column( $out, 'id' ), 'A copied element keeps its uid until saved; a path-like uid is never an id.' );
	}

	public function testEnfoldLockedElementsTemplatesAndPlaceholders(): void {
		$layout = "[av_codeblock wrapper_element='' escape_html='' av_uid='av-c1']<script>steal()</script>[/av_codeblock]"
			. "[av_contact email='chef@example.ch' title='Schreiben Sie uns' av_uid='av-c2'][av_contact_field label='Name' av_uid='av-c3'][/av_contact]"
			. "[av_textblock select_element_template='77' av_uid='av-c4']<p>Hallo {wp_post_title}</p>[/av_textblock]";
		$out    = $this->outline( 'enfold', $this->enfoldPage( 915, $layout ) );
		self::assertSame( array( 'av-c1', 'av-c2', 'av-c4' ), array_column( $out, 'id' ) );
		self::assertSame( array( 'code element', 'form' ), array( $out[0]['reason'], $out[1]['reason'] ) );
		foreach ( array( 'steal', 'chef@example.ch', 'Schreiben Sie uns', 'Name' ) as $never ) {
			self::assertStringNotContainsString( $never, $this->flat( $out ) );
		}
		self::assertTrue( $out[2]['global'] );
		self::assertSame( 'Hallo {wp_post_title}', self::field( $out[2], 'text' ) );
		self::assertStringContainsString( 'uses Custom Element Template 77', $out[2]['note'] );
		self::assertStringContainsString( 'text: holds placeholders', $out[2]['note'] );
	}

	/* ------------------------------------------- the tools on these pages */

	public function testTheLayoutToolOnAWpbakeryPage(): void {
		ab_test_add_user( 7 );
		$GLOBALS['ab_test_current_user'] = 7;
		$this->mayDoAnything();
		$this->builders( array( 'wpbakery' ) );
		$this->page( 920, '[vc_row][vc_column]' . self::WPB_HEADING . self::WPB_BUTTON . '[vc_raw_html]eA==[/vc_raw_html][/vc_column][/vc_row]', array( '_wpb_vc_js_status' => 'true', '_wpb_shortcodes_custom_css' => '.vc_custom_1{color:red}' ) );
		$out = AB_MCP_Tools_Builders::get_builder_layout( array( 'id' => 920 ) );
		self::assertSame( 'wpbakery', $out['builder'] );
		self::assertSame( 'A', $out['storage'] );
		self::assertSame( 'read', $out['support'] );
		self::assertFalse( $out['verified'], 'Read from vendor documentation.' );
		self::assertSame( array( 'wp_update_post' ), $out['write_via'] );
		self::assertSame( array( array( 'loc' => 'post_content', 'shown' => true, 'role' => 'source' ) ), $out['copies'] );
		self::assertSame( 5, $out['element_count'] );
		self::assertStringContainsString( '2 elements were read without a measured profile', implode( ' ', $out['notes'] ) );
		self::assertStringNotContainsString( 'color:red', $this->flat( $out ) );
	}

	public function testTheLayoutToolOnAnEnfoldPage(): void {
		ab_test_add_user( 7 );
		$GLOBALS['ab_test_current_user'] = 7;
		$this->mayDoAnything();
		$this->builders( array( 'enfold' ) );
		$this->enfoldPage( 921, self::ENF_HEADING );
		$out = AB_MCP_Tools_Builders::get_builder_layout( array( 'id' => 921 ) );
		self::assertSame( 'enfold', $out['builder'] );
		self::assertSame( 'B', $out['storage'] );
		self::assertSame( 'read', $out['support'] );
		self::assertSame(
			array(
				array( 'loc' => 'meta:_aviaLayoutBuilderCleanData', 'shown' => true, 'role' => 'source' ),
				array( 'loc' => 'post_content', 'shown' => false, 'role' => 'copy' ),
			),
			$out['copies']
		);
		self::assertSame( array(), $out['write_via'], 'No tool of the free plugin writes the layout meta.' );
		self::assertSame( array( 'av-mb1x2y' ), array_column( $out['elements'], 'id' ) );
		self::assertStringContainsString( 'post_content is only a copy', implode( ' ', $out['notes'] ) );

		$hash = $out['layout_hash'];
		self::assertSame( $hash, AB_MCP_Tools_Builders::get_builder_layout( array( 'id' => 921 ) )['layout_hash'], 'Stable while nothing changes.' );
		$GLOBALS['ab_test_meta'][921]['_aviaLayoutBuilderCleanData'] = array( str_replace( 'Über uns', 'Team', self::ENF_HEADING ) );
		self::assertNotSame( $hash, AB_MCP_Tools_Builders::get_builder_layout( array( 'id' => 921 ) )['layout_hash'], 'The meta is a copy the hash covers.' );
	}

	public function testContentOfAnActiveEnfoldPageIsNotWrittenToTheCopy(): void {
		ab_test_add_user( 7 );
		$GLOBALS['ab_test_current_user'] = 7;
		$GLOBALS['ab_test_now']          = strtotime( '2026-10-01 12:00:00 UTC' );
		$this->mayDoAnything();
		$this->builders( array( 'enfold' ) );
		$this->enfoldPage( 922, self::ENF_TEXT );

		$out = AB_MCP_Tools_Content::update_post( array( 'id' => 922, 'content' => '[av_textblock]Neu[/av_textblock]' ) );
		self::assertTrue( is_wp_error( $out ) );
		self::assertSame( 'ab_mcp_builder_content_copy', $out->get_error_code() );
		self::assertStringContainsString( 'Enfold (Avia Layout Builder) editor in wp-admin', $out->get_error_message() );
		self::assertSame( '[av_textblock]Alte Kopie[/av_textblock]', get_post( 922 )->post_content );

		// Enfold switched off: WordPress shows post_content, so the change is visible.
		$this->builders( array(), array( 'enfold' ) );
		$out = AB_MCP_Tools_Content::update_post( array( 'id' => 922, 'content' => '<p>Neu</p>' ) );
		self::assertIsArray( $out );
		self::assertSame( '<p>Neu</p>', get_post( 922 )->post_content );
		self::assertStringContainsString( 'is not active', $out['builder_note'] );
	}

	/* -------------------------------------------- profiles as data, safely */

	public function testOptionsCapAndCut(): void {
		$long = str_repeat( 'Wort ', 60 );
		$out  = $this->read( 'wpbakery', '[vc_row][vc_column][vc_column_text]' . $long . '[/vc_column_text]' . self::WPB_BUTTON . '[/vc_column][/vc_row]', array( 'max_field_chars' => 100, 'max_elements' => 3 ) );
		self::assertCount( 3, $out );
		self::assertSame( 100, mb_strlen( self::field( $out[2], 'text' ) ) );
		self::assertTrue( $out[2]['fields']['text']['truncated'] );
	}

	public function testAProfileCanNeverReadCodeStylingOrMarkup(): void {
		add_filter(
			'ab_mcp_builder_shortcode_profiles',
			static function ( $profiles ) {
				foreach ( $profiles as $i => $p ) {
					if ( 'wpbakery' === $p['builder'] ) {
						$profiles[ $i ]['tags']['vc_btn']['code_attrs'][]     = 'run_this';
						$profiles[ $i ]['tags']['vc_btn']['fields']['a']      = array( 'kind' => 'text', 'from' => 'attr', 'attr' => 'css' );
						$profiles[ $i ]['tags']['vc_btn']['fields']['b']      = array( 'kind' => 'text', 'from' => 'attr', 'attr' => 'custom_onclick_code' );
						$profiles[ $i ]['tags']['vc_btn']['fields']['c']      = array( 'kind' => 'text', 'from' => 'attr', 'attr' => 'el_class' );
						$profiles[ $i ]['tags']['vc_btn']['fields']['d']      = array( 'kind' => 'html', 'from' => 'attr', 'attr' => 'onClick' );
						$profiles[ $i ]['tags']['vc_btn']['fields']['e']      = array( 'kind' => 'text', 'from' => 'attr', 'attr' => 'run_this' );
						$profiles[ $i ]['tags']['vc_btn']['fields']['f']      = array( 'kind' => 'text', 'from' => 'attr', 'attr' => 'inline_style' );
						$profiles[ $i ]['tags']['vc_btn']['fields']['g']      = array( 'kind' => 'text', 'from' => 'attr', 'attr' => 'custom_html' );
					}
				}
				return $profiles;
			}
		);
		AB_MCP_Builders::reset();
		$fields = AB_MCP_Builders::adapter_for_builder( 'wpbakery' )->profile()['tags']['vc_btn']['fields'];
		self::assertSame( array( 'text', 'url' ), array_keys( $fields ), 'Every field naming code, styling or markup is dropped.' );

		$out = $this->read( 'wpbakery', '[vc_btn title="Kaufen" ' . self::WPB_CSS . ' custom_onclick_code="pay()" el_class="hot" onclick="evil()" run_this="rm()" inline_style="color:red" custom_html="<b>x</b>"]' );
		$flat = $this->flat( $out );
		foreach ( array( 'margin-bottom', 'pay()', 'hot', 'evil()', 'rm()', 'color:red', '<b>' ) as $never ) {
			self::assertStringNotContainsString( $never, $flat );
		}
		self::assertSame( 'Kaufen', self::field( $out[0], 'text' ) );
		self::assertStringContainsString( 'holds code in run_this, not shown', $out[0]['note'] );
	}

	public function testMalformedProfilesAreDropped(): void {
		add_filter(
			'ab_mcp_builder_shortcode_profiles',
			static function ( $profiles ) {
				$profiles[] = 'nonsense';
				$profiles[] = array( 'builder' => 'no-tags' );
				$profiles[] = array(
					'builder'      => 'acme-sc',
					'codec'        => 'rot13',
					'placeholder'  => '~(unclosed~',
					'global_attrs' => array( 'g' => '%s and %d' ),
					'family'       => array( '*', 'acme_*' ),
					'tags'         => array(
						'bad tag'   => array( 'container' => true ),
						'acme_text' => array(
							'ref_note' => '%s %n',
							'fields'   => array(
								'ok'      => array( 'kind' => 'text', 'from' => 'content' ),
								'kind'    => array( 'kind' => 'script', 'from' => 'content' ),
								'from'    => array( 'kind' => 'text', 'from' => 'meta' ),
								'noattr'  => array( 'kind' => 'text', 'from' => 'attr' ),
								'format'  => array( 'kind' => 'url', 'from' => 'attr', 'attr' => 'link', 'format' => 'eval' ),
							),
						),
					),
				);
				return $profiles;
			}
		);
		$p = array();
		foreach ( AB_MCP_Builder_Adapter_Shortcodes::profiles() as $profile ) {
			$p[ $profile['builder'] ] = $profile;
		}
		self::assertArrayNotHasKey( 'no-tags', $p );
		$acme = $p['acme-sc'];
		self::assertSame( '', $acme['codec'] );
		self::assertSame( '', $acme['placeholder'] );
		self::assertSame( array(), $acme['global_attrs'] );
		self::assertSame( array( 'acme_*' ), $acme['family'] );
		self::assertSame( array( 'acme_text' ), array_keys( $acme['tags'] ) );
		self::assertSame( '', $acme['tags']['acme_text']['ref_note'] );
		self::assertSame( array( 'ok', 'format' ), array_keys( $acme['tags']['acme_text']['fields'] ) );
		self::assertSame( '', $acme['tags']['acme_text']['fields']['format']['format'], 'An unknown format reads the value as it is.' );
	}

	public function testAThirdPartyShortcodeBuilderNeedsOnlyDataAndASignature(): void {
		add_filter(
			'ab_mcp_builder_signatures',
			static function ( $sigs ) {
				$sigs['acme-sc'] = array(
					'name'    => 'Acme Shortcodes',
					'family'  => 'shortcodes',
					'storage' => 'A',
					'markers' => array( array( 'content' => '[acme_row' ) ),
					'copies'  => array( array( 'loc' => 'post_content', 'shown' => true, 'role' => 'source' ) ),
				);
				return $sigs;
			}
		);
		add_filter(
			'ab_mcp_builder_shortcode_profiles',
			static function ( $profiles ) {
				$profiles[] = array(
					'builder' => 'acme-sc',
					'family'  => array( 'acme_*' ),
					'tags'    => array(
						'acme_row'  => array( 'container' => true ),
						'acme_text' => array( 'fields' => array( 'text' => array( 'kind' => 'html', 'from' => 'content' ) ) ),
					),
				);
				return $profiles;
			}
		);
		AB_MCP_Builders::reset();
		$post = $this->page( 930, '[acme_row][acme_text]<p>Hallo</p>[/acme_text][/acme_row]' );
		self::assertSame( 'acme-sc', AB_MCP_Builders::primary( $post )['id'] );
		self::assertSame( 'read', AB_MCP_Builders::primary( $post )['support'] );
		$out = AB_MCP_Builders::for_post( $post )->outline( $post, array() );
		self::assertSame( 'Hallo', self::field( $out[1], 'text' ) );
	}
}
