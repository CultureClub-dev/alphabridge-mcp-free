<?php
/**
 * What locks an object of the database kinds, against WordPress 7.1.3:
 *
 * - the builder release list (decision 31): WPBakery, Divi 4 and Divi 5
 *   pages are locked, Kadence and the other released block libraries not;
 * - the credential check (decision 9): a meta row or option named like a
 *   credential is B9, counted and out of the form; a field inside a
 *   serialized value, a JSON text or a list of name and value makes the
 *   unit mixed and locks its object;
 * - a possible reference (decision 12) locks where its number is the
 *   staging id of an object in the scope with another live id, and only
 *   there;
 * - a value with a PHP object locks, without a class being made;
 * - the menu, its items and a widget area read by their schemas.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests\Integration;

use AB_MCP_IT_Sites;
use AB_MCP_IT_Sync_Steps;
use AB_MCP_Sync_Db_Kinds;
use AB_MCP_Sync_Inventory;
use AB_MCP_Sync_Option_Schema;
use AB_MCP_Sync_Refs;
use PHPUnit\Framework\TestCase;

/**
 * A class whose __wakeup() counts: what makes an object of a stored value
 * runs it.
 */
final class SyncWakeupProbe {
	/** @var int */
	public static $woken = 0;

	public function __wakeup() {
		++self::$woken;
	}
}

final class SyncLocksTest extends TestCase {

	protected function setUp(): void {
		AB_MCP_IT_Sites::reset( 'a' );
		wp_set_current_user( (int) AB_MCP_IT_Sites::site( 'a' )['admin']['user_id'] );
	}

	public static function tearDownAfterClass(): void {
		wp_set_current_user( 0 );
		AB_MCP_IT_Sites::reset( 'a' );
	}

	/** The canonical result of an object by its pair kind and ref, as the inventory names it. */
	private static function canon( string $pair, $ref ): array {
		$result = self::canonical( $pair, $ref );
		self::assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		return $result;
	}

	/** @return array|\WP_Error */
	private static function canonical( string $pair, $ref ) {
		$kind = AB_MCP_Sync_Db_Kinds::for_pair( $pair );
		self::assertNotNull( $kind, "No kind has $pair." );
		return $kind->canonical(
			array(
				'kind'   => $pair,
				'ref'    => (string) $ref,
				'source' => AB_MCP_Sync_Inventory::source( AB_MCP_IT_Sync_Steps::addresses() ),
			)
		);
	}

	private static function post( string $content, array $meta = array() ): int {
		$id = wp_insert_post(
			array(
				'post_title'   => 'Sperre',
				'post_content' => $content,
				'post_status'  => 'publish',
				'post_type'    => 'page',
			),
			true
		);
		self::assertIsInt( $id );
		foreach ( $meta as $key => $value ) {
			add_post_meta( $id, $key, wp_slash( $value ) );
		}
		return $id;
	}

	/** @return string[] The builders a result is locked for. */
	private static function builders( array $result ): array {
		$out = array();
		foreach ( $result['locks'] as $lock ) {
			if ( 'post:builder' === $lock['key'] ) {
				$out[] = substr( $lock['at'], strlen( 'builder:' ) );
			}
		}
		sort( $out );
		return $out;
	}

	/* ---------------------------------------------------------- builder */

	public function testBuildersOtherThanTheSixBlockLibrariesAreLocked(): void {
		$cases = array(
			'wpbakery'       => array( '[vc_row][vc_column][vc_column_text]Text[/vc_column_text][/vc_column][/vc_row]', array( '_wpb_vc_js_status' => 'true' ), array( 'wpbakery' ) ),
			'divi4'          => array( '[et_pb_section][et_pb_row][/et_pb_row][/et_pb_section]', array( '_et_pb_use_builder' => 'on' ), array( 'divi4' ) ),
			'divi5'          => array( '<!-- wp:divi/section --><div></div><!-- /wp:divi/section -->', array(), array( 'divi5' ) ),
			'elementor'      => array( '<p>copy</p>', array( '_elementor_edit_mode' => 'builder', '_elementor_data' => '[]' ), array( 'elementor' ) ),
			'kadence'        => array( '<!-- wp:kadence/rowlayout {"uniqueID":"_a1"} --><div></div><!-- /wp:kadence/rowlayout -->', array(), array() ),
			'spectra'        => array( '<!-- wp:uagb/container {"block_id":"a1"} --><div></div><!-- /wp:uagb/container -->', array(), array() ),
			'generateblocks' => array( '<!-- wp:generateblocks/container {"uniqueId":"a1"} --><div></div><!-- /wp:generateblocks/container -->', array(), array() ),
			'stackable'      => array( '<!-- wp:stackable/columns {"uniqueId":"a1"} --><div></div><!-- /wp:stackable/columns -->', array(), array() ),
			'otter'          => array( '<!-- wp:themeisle-blocks/button {"id":"a1"} --><div></div><!-- /wp:themeisle-blocks/button -->', array(), array() ),
			'coblocks'       => array( '<!-- wp:coblocks/accordion --><div></div><!-- /wp:coblocks/accordion -->', array(), array() ),
			'core blocks'    => array( '<!-- wp:paragraph --><p>Text</p><!-- /wp:paragraph -->', array(), array() ),
		);
		foreach ( $cases as $what => list( $content, $meta, $locked ) ) {
			$result = self::canon( 'post', self::post( $content, $meta ) );
			self::assertSame( $locked, self::builders( $result ), $what );
			if ( array() !== $locked ) {
				self::assertSame( 'Z8c', $result['locks'][0]['stage'], $what );
			}
		}
	}

	/* ------------------------------------------------------ credentials */

	public function testANameOfACredentialIsB9AndAFieldInsideLocks(): void {
		$id     = self::post(
			'<!-- wp:paragraph --><p>Text</p><!-- /wp:paragraph -->',
			array(
				'api_key'          => 'sk_live_123',
				'ab_it_settings'   => array( 'title' => 'x', 'clientSecret' => 'geheim' ),
				'ab_it_json'       => '{"service":{"access_token":"abc"}}',
				'ab_it_fields'     => array( array( 'name' => 'smtp_pass', 'value' => 'pw' ), array( 'name' => 'title', 'value' => 'ok' ) ),
				'ab_it_harmless'   => array( 'hasPassword' => true, 'token_count' => 3 ),
				'ab_it_deep'       => str_repeat( '[', 66 ) . '{"api_key":"y"}' . str_repeat( ']', 66 ),
				'ab_it_literal'    => json_encode( json_encode( array( 'api_key' => 'y' ) ) ),
			)
		);
		$result = self::canon( 'post', $id );
		self::assertSame( array( 'meta:api_key' ), $result['credentials']['b9'] );
		$keys = array_column( $result['form']['meta'], 0 );
		self::assertNotContains( 'api_key', $keys, 'A B9 row is not in the form.' );
		self::assertStringNotContainsString( 'sk_live_123', json_encode( $result ), 'Nor its value anywhere in the result.' );
		$mixed = array_column( $result['credentials']['mixed'], 'fields', 'unit' );
		self::assertSame(
			array(
				'meta:ab_it_fields'   => array( '0.smtp_pass' ),
				'meta:ab_it_json'     => array( 'service.access_token' ),
				'meta:ab_it_literal'  => array( 'api_key' ),
				'meta:ab_it_settings' => array( 'clientSecret' ),
			),
			$mixed
		);
		self::assertSame( array( 'meta:ab_it_fields', 'meta:ab_it_json', 'meta:ab_it_literal', 'meta:ab_it_settings' ), array_column( array_filter( $result['locks'], static fn( $l ) => '*:credential_field' === $l['key'] ), 'at' ) );
		self::assertSame( array( 'meta:ab_it_deep' ), array_column( array_filter( $result['locks'], static fn( $l ) => '*:unreadable_value' === $l['key'] ), 'at' ), 'Deeper than the check reads: locked, never clean.' );

		// Decision 9 for the attributes of blocks, a released library too.
		$kadence = self::canon( 'post', self::post( '<!-- wp:kadence/rowlayout {"uniqueID":"_a1","settings":{"api_key":"sk_live_789"}} --><div></div><!-- /wp:kadence/rowlayout -->' ) );
		self::assertSame( array( array( 'unit' => 'post_content:kadence/rowlayout', 'fields' => array( 'settings.api_key' ) ) ), $kadence['credentials']['mixed'] );
		self::assertSame( array( '*:credential_field' ), array_column( $kadence['locks'], 'key' ) );
		self::assertStringNotContainsString( 'sk_live_789', json_encode( array( $kadence['credentials'], $kadence['locks'] ) ) );

		update_option( 'ab_it_service_api_key', 'sk_live_456' );
		$entry = self::canon( 'option', 'ab_it_service_api_key' );
		self::assertNull( $entry['hash'], 'A B9 option is never hashed.' );
		self::assertSame( 'b9', $entry['status'] );
	}

	/* ----------------------------------------------- possible reference */

	public function testAWholeNumberWithoutASchemaLocksWhereItEqualsAMovedId(): void {
		$target = self::post( '<!-- wp:paragraph --><p>Ziel</p><!-- /wp:paragraph -->' );
		$id     = self::post(
			'<!-- wp:paragraph --><p>Seite 12 von 30</p><!-- /wp:paragraph -->' . "\n" . '<!-- wp:acme/related {"post":' . $target . ',"count":3} /-->' . "\n" . '<!-- wp:heading {"level":2} --><h2>Kopf</h2><!-- /wp:heading -->' . "\n" . '<!-- wp:paragraph --><p>[contact-form-7 id="' . $target . '"]</p><!-- /wp:paragraph -->',
			array(
				'ab_it_related' => (string) $target,
				'ab_it_json'    => '{"box":{"post":' . $target . '}}',
				'ab_it_nested'  => json_encode( array( 'outer' => json_encode( array( 'target' => $target ) ) ) ),
			)
		);
		$result = self::canon( 'post', $id );
		$values = array_column( $result['possible'], 'value', 'at' );
		self::assertSame( $target, $values['post_content:acme/related.post'] );
		self::assertSame( 3, $values['post_content:acme/related.count'] );
		self::assertSame( $target, $values['post_content:core/paragraph:shortcode:contact-form-7.id'] );
		self::assertSame( $target, $values['meta:ab_it_related'] );
		self::assertSame( $target, $values['meta:ab_it_json.box.post'], 'A number in JSON in a meta value.' );
		self::assertSame( $target, $values['meta:ab_it_nested.outer.target'], 'A number in JSON in a JSON text in a meta value.' );
		self::assertCount( 6, $result['possible'], 'Not the number in the text, not the level of the core heading.' );

		// Staging id $target, live id another: locked; the same id: not.
		$locked = AB_MCP_Sync_Refs::lock_possible( $result, array( 'post' => array( $target => $target + 1000 ) ) );
		self::assertContains( '*:possible_reference', array_column( $locked['locks'], 'key' ) );
		self::assertCount( 5, $locked['possible_hits'] );
		$new = AB_MCP_Sync_Refs::lock_possible( $result, array( 'post' => array( $target => null ) ) );
		self::assertContains( '*:possible_reference', array_column( $new['locks'], 'key' ), 'An object without a live id yet.' );
		$same = AB_MCP_Sync_Refs::lock_possible( $result, array( 'post' => array( $target => $target ), 'term' => array( 3 => 3 ) ) );
		self::assertSame( array(), $same['possible_hits'] );
		$term = AB_MCP_Sync_Refs::lock_possible( $result, array( 'term' => array( 3 => 9 ) ) );
		self::assertSame( array( 'term' ), array_values( array_unique( array_column( $term['possible_hits'], 'space' ) ) ), 'Both ID spaces are asked.' );
	}

	/* ----------------------------------------------------------- object */

	public function testAValueWithAnObjectLocksAndNoClassIsMade(): void {
		global $wpdb;
		$id = self::post( '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' );
		$wpdb->insert(
			$wpdb->postmeta,
			array(
				'post_id'    => $id,
				'meta_key'   => 'ab_it_object',
				'meta_value' => 'O:21:"AB_IT_Canon_Exploding":1:{s:1:"a";i:1;}',
			)
		);
		$wpdb->insert(
			$wpdb->postmeta,
			array(
				'post_id'    => $id,
				'meta_key'   => 'ab_it_reference',
				'meta_value' => 'a:2:{i:0;a:1:{i:0;i:1;}i:1;R:2;}',
			)
		);
		wp_cache_delete( $id, 'post_meta' );
		$result = self::canon( 'post', $id );
		self::assertFalse( class_exists( 'AB_IT_Canon_Exploding', false ) );
		self::assertSame( array( 'builder', 'meta:ab_it_object', 'meta:ab_it_reference' ), array_column( array_filter( $result['locks'], static fn( $l ) => '*:php_object' === $l['key'] ), 'at' ), 'The rows, and the builders that could not be asked.' );
	}

	/* ------------------------------------- no object made on the way */

	public function testNoStoredObjectIsMadeOnTheWayItLocksInstead(): void {
		global $wpdb;
		SyncWakeupProbe::$woken = 0;
		$payload                = serialize( new SyncWakeupProbe() );
		$id                     = self::post( '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' );
		// A marker detect_all() reads through get_post_meta(), holding an object.
		$wpdb->insert( $wpdb->postmeta, array( 'post_id' => $id, 'meta_key' => '_elementor_edit_mode', 'meta_value' => $payload ) );
		$wpdb->insert( $wpdb->options, array( 'option_name' => 'ab_it_object_option', 'option_value' => $payload, 'autoload' => 'off' ) );
		wp_cache_delete( $id, 'post_meta' );
		wp_cache_delete( 'notoptions', 'options' );

		$result = self::canon( 'post', $id );
		self::assertSame( 0, SyncWakeupProbe::$woken, 'The canonical form of the post made no object.' );
		self::assertContains( 'builder', array_column( array_filter( $result['locks'], static fn( $l ) => '*:php_object' === $l['key'] ), 'at' ), 'The builders could not be asked: locked.' );

		$class = AB_MCP_Sync_Option_Schema::classify( 'ab_it_object_option' );
		self::assertSame( 0, SyncWakeupProbe::$woken, 'Classifying the option made no object.' );
		self::assertSame( 'unclassified', $class['status'] );
		$option = self::canon( 'option', 'ab_it_object_option' );
		self::assertContains( '*:php_object', array_column( $option['locks'], 'key' ) );
		AB_MCP_Sync_Inventory::run( AB_MCP_Sync_Inventory::source( AB_MCP_IT_Sync_Steps::addresses() ) );
		self::assertSame( 0, SyncWakeupProbe::$woken, 'The whole inventory made no object.' );

		// The way WordPress reads it would have made one.
		get_post_meta( $id, '_elementor_edit_mode', true );
		self::assertSame( 1, SyncWakeupProbe::$woken, 'The probe counts.' );
	}

	/* ------------------------------------------------ menu and widgets */

	public function testMenusTheirItemsAndAWidgetAreaReadByTheirSchemas(): void {
		$page = self::post( '<!-- wp:paragraph --><p>Ziel</p><!-- /wp:paragraph -->' );
		$cat  = wp_insert_term( 'Menükategorie', 'category' );
		$menu = wp_create_nav_menu( 'Haupt' );
		$top  = wp_update_nav_menu_item( $menu, 0, array( 'menu-item-type' => 'post_type', 'menu-item-object' => 'page', 'menu-item-object-id' => $page, 'menu-item-status' => 'publish' ) );
		$sub  = wp_update_nav_menu_item( $menu, 0, array( 'menu-item-type' => 'taxonomy', 'menu-item-object' => 'category', 'menu-item-object-id' => $cat['term_id'], 'menu-item-parent-id' => $top, 'menu-item-status' => 'publish' ) );
		$link = wp_update_nav_menu_item( $menu, 0, array( 'menu-item-type' => 'custom', 'menu-item-title' => 'Kontakt', 'menu-item-url' => AB_MCP_IT_Sites::url( 'a' ) . '/kontakt/', 'menu-item-status' => 'publish' ) );
		set_theme_mod( 'nav_menu_locations', array( 'primary' => $menu, 'footer' => $menu ) );

		$m = self::canon( 'menu', $menu );
		self::assertSame( 'menu', $m['kind'] );
		self::assertSame( array( '~set' => array( 'primary', 'footer' ) ), $m['form']['locations'] );

		$refs = static function ( array $result ): array {
			$out = array();
			foreach ( $result['refs'] as $ref ) {
				$out[ $ref['at'] ] = $ref['space'] . ':' . $ref['id'];
			}
			return $out;
		};
		self::assertSame( array( 'menu' => 'term:' . $menu, 'meta:_menu_item_object_id' => 'post:' . $page ), $refs( self::canon( 'menu_item', $top ) ) );
		self::assertSame( array( 'menu' => 'term:' . $menu, 'meta:_menu_item_menu_item_parent' => 'post:' . $top, 'meta:_menu_item_object_id' => 'term:' . $cat['term_id'] ), $refs( self::canon( 'menu_item', $sub ) ) );
		$custom = self::canon( 'menu_item', $link );
		$meta   = array_column( $custom['form']['meta'], 1, 0 );
		self::assertSame( array( '~ref' => 'self' ), $meta['_menu_item_object_id'], 'A custom link names itself.' );
		self::assertSame( array( '~s' => array( array( '~addr' => 'plain' ), '/kontakt/' ) ), $meta['_menu_item_url'] );
		self::assertSame( array(), $custom['possible'] );

		// A widget area with a core widget that names a menu and one that
		// names an image.
		$image = wp_insert_attachment( array( 'post_mime_type' => 'image/jpeg', 'post_title' => 'Bild', 'post_status' => 'inherit' ), 'bild.jpg' );
		update_option( 'widget_nav_menu', array( 3 => array( 'title' => 'Menü', 'nav_menu' => $menu ), '_multiwidget' => 1 ) );
		update_option( 'widget_media_image', array( 2 => array( 'attachment_id' => $image, 'url' => AB_MCP_IT_Sites::url( 'a' ) . '/wp-content/uploads/bild.jpg', 'width' => 300 ), '_multiwidget' => 1 ) );
		update_option( 'widget_acme_box', array( 4 => array( 'post' => $page ), '_multiwidget' => 1 ) );
		update_option( 'sidebars_widgets', array( 'wp_inactive_widgets' => array(), 'sidebar-1' => array( 'nav_menu-3', 'media_image-2', 'acme_box-4' ), 'array_version' => 3 ) );
		$area = self::canon( 'widget_area', 'sidebar-1' );
		self::assertSame( array( 'widget:nav_menu-3.nav_menu' => 'term:' . $menu, 'widget:media_image-2.attachment_id' => 'post:' . $image ), $refs( $area ) );
		self::assertSame( array( array( 'value' => $page, 'at' => 'widget:acme_box-4.post' ) ), $area['possible'], 'Only the widget of no schema has possible references; width is a plain number of the core widget.' );
		self::assertSame( array( 'nav_menu', 'media_image', 'acme_box' ), array_column( $area['form']['instances'], 'base' ) );
	}

	/* ------------------------------------------- types of the table */

	public function testAPostTypeOfAPluginIsListedWithItsLock(): void {
		global $wpdb;
		$wpdb->insert( $wpdb->posts, array( 'post_type' => 'acme_product', 'post_title' => 'Produkt', 'post_status' => 'publish', 'post_name' => 'produkt', 'post_content' => '', 'post_excerpt' => '', 'to_ping' => '', 'pinged' => '', 'post_content_filtered' => '' ) );
		$id      = (int) $wpdb->insert_id;
		$result  = self::canon( 'post', $id );
		$by_type = array_values( array_filter( $result['locks'], static fn( $l ) => 'type' === $l['at'] ) );
		self::assertSame( 'post_type:*', $by_type[0]['key'] );
		self::assertSame( 'Z8d', $by_type[0]['stage'] );
		$all = AB_MCP_Sync_Inventory::run( AB_MCP_Sync_Inventory::source( AB_MCP_IT_Sync_Steps::addresses() ) );
		$hit = array_values( array_filter( $all['objects'], static fn( $o ) => 'post' === $o['kind'] && (string) $id === $o['ref'] ) );
		self::assertCount( 1, $hit, 'Listed for the report, with its hash.' );
		self::assertSame( 'locked', $hit[0]['status'] );
		self::assertNotNull( $hit[0]['hash'] );
	}

	public function testTheAdditionalCssOfAStylesheetIsItsObject(): void {
		$post = wp_update_custom_css_post( 'body{background:url(' . AB_MCP_IT_Sites::url( 'a' ) . '/bg.png)}' );
		self::assertInstanceOf( \WP_Post::class, $post );
		$stylesheet = get_stylesheet();
		$all        = AB_MCP_Sync_Inventory::run( AB_MCP_Sync_Inventory::source( AB_MCP_IT_Sync_Steps::addresses() ) );
		$css        = array_values( array_filter( $all['objects'], static fn( $o ) => 'custom_css' === $o['kind'] ) );
		self::assertCount( 1, $css );
		self::assertSame( $stylesheet, $css[0]['ref'], 'Its identity is the stylesheet the theme mods name it for.' );
		self::assertSame( array( 'stylesheet' => $stylesheet, 'post' => $post->ID, 'linked' => true ), $css[0]['identity'] );
		$form = self::canon( 'custom_css', $stylesheet )['form'];
		self::assertSame( array( '~s' => array( 'body{background:url(', array( '~addr' => 'plain' ), '/bg.png)}' ) ), $form['css'] );
		self::assertNotContains( 'post:' . $post->ID, array_map( static fn( $o ) => $o['kind'] . ':' . $o['ref'], $all['objects'] ), 'Not a post of the post kind.' );
		// A CSS post the theme mods do not name keeps its id in the ref.
		set_theme_mod( 'custom_css_post_id', -1 );
		$again = self::canon( 'custom_css', $stylesheet . '@' . $post->ID );
		self::assertSame( $stylesheet . '@' . $post->ID, $again['ref'] );
		self::assertInstanceOf( \WP_Error::class, self::canonical( 'custom_css', $stylesheet ), 'The stylesheet names no CSS while the theme mods name none.' );
	}
}
