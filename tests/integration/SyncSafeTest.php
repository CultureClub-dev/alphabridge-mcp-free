<?php
/**
 * The gate of AB_MCP_Sync_Safe, measured: objects of a class that counts its
 * __wakeup() stand serialized in every option the takeover's code touches
 * through a function of WordPress (upload_path, upload_url_path, the row an
 * alias lands on, the theme mods), in the builder marker of a post, the meta
 * of a term, a menu item and an attachment. Then the whole inventory, the
 * canonical form of every object it lists, the file list of the attachment,
 * the reverse map and the classification of the alias run — and no object
 * is made: 0 calls of __wakeup(), the objects locked instead. Reading the
 * same rows the way WordPress does makes them.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests\Integration;

use AB_MCP_IT_Sites;
use AB_MCP_IT_Sync_Steps;
use AB_MCP_Sync_Db_Kinds;
use AB_MCP_Sync_Inventory;
use AB_MCP_Sync_Media;
use AB_MCP_Sync_Option_Schema;
use AB_MCP_Sync_Safe;
use PHPUnit\Framework\TestCase;

/**
 * Counts every object of it that unserialize() makes.
 */
final class SyncSafeWakeupProbe {
	/** @var int */
	public static $woken = 0;

	/** @var string */
	public $note = 'probe';

	public function __wakeup() {
		++self::$woken;
	}
}

final class SyncSafeTest extends TestCase {

	protected function setUp(): void {
		AB_MCP_IT_Sites::reset( 'a' );
		wp_set_current_user( (int) AB_MCP_IT_Sites::site( 'a' )['admin']['user_id'] );
	}

	protected function tearDown(): void {
		AB_MCP_IT_Sites::reset( 'a' );
		wp_upload_dir( null, false, true );
	}

	public static function tearDownAfterClass(): void {
		wp_set_current_user( 0 );
	}

	private static function set_option( string $name, string $value ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = BINARY %s", $name ) );
		$wpdb->insert( $wpdb->options, array( 'option_name' => $name, 'option_value' => $value, 'autoload' => 'off' ) );
	}

	private static function canon( string $pair, string $ref ) {
		return AB_MCP_Sync_Db_Kinds::for_pair( $pair )->canonical(
			array(
				'kind'   => $pair,
				'ref'    => $ref,
				'source' => AB_MCP_Sync_Inventory::source( AB_MCP_IT_Sync_Steps::addresses() ),
			)
		);
	}

	/** @return string[] The keys of the locks of a result. */
	private static function locks( $result ): array {
		return array_values( array_unique( array_column( $result['locks'], 'key' ) ) );
	}

	public function testNoStoredObjectWakesAnywhereAndEachLocks(): void {
		global $wpdb;
		$payload = serialize( new SyncSafeWakeupProbe() );

		// The content, made through WordPress before any object is there.
		$post = wp_insert_post( array( 'post_title' => 'Seite', 'post_status' => 'publish', 'post_type' => 'page', 'post_content' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' ) );
		$term = wp_insert_term( 'Begriff', 'category' );
		$menu = wp_create_nav_menu( 'Menü' );
		$item = wp_update_nav_menu_item( $menu, 0, array( 'menu-item-type' => 'custom', 'menu-item-title' => 'Link', 'menu-item-url' => 'https://example.test/', 'menu-item-status' => 'publish' ) );
		$att  = wp_insert_post( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => 'Bild', 'post_mime_type' => 'image/jpeg' ) );
		add_post_meta( $att, '_wp_attached_file', '2026/11/bild.jpg' );
		$stylesheet = get_stylesheet();

		// The objects, written past WordPress.
		$wpdb->insert( $wpdb->postmeta, array( 'post_id' => $post, 'meta_key' => '_elementor_edit_mode', 'meta_value' => $payload ) );
		$wpdb->insert( $wpdb->termmeta, array( 'term_id' => (int) $term['term_id'], 'meta_key' => '_ab_it_marker', 'meta_value' => $payload ) );
		$wpdb->insert( $wpdb->postmeta, array( 'post_id' => $item, 'meta_key' => '_menu_item_ab_it', 'meta_value' => $payload ) );
		$wpdb->insert( $wpdb->postmeta, array( 'post_id' => $att, 'meta_key' => '_wp_attachment_metadata', 'meta_value' => $payload ) );
		self::set_option( 'upload_path', $payload );
		self::set_option( 'upload_url_path', $payload );
		self::set_option( ' ab_it_alias', 'harmlos' );
		self::set_option( 'ab_it_alias', $payload );
		self::set_option( 'theme_mods_' . $stylesheet, serialize( array( 'header_textcolor' => new SyncSafeWakeupProbe(), 'nav_menu_locations' => array( 'primary' => $menu ) ) ) );
		wp_cache_flush();
		SyncSafeWakeupProbe::$woken = 0;

		// Everything the takeover reads.
		$all = AB_MCP_Sync_Inventory::run( AB_MCP_Sync_Inventory::source( AB_MCP_IT_Sync_Steps::addresses() ) );
		self::assertIsArray( $all );
		self::assertNull( $all['cursor'] );
		$results = array();
		foreach ( $all['objects'] as $object ) {
			$again = self::canon( $object['kind'], $object['ref'] );
			self::assertIsArray( $again, $object['kind'] . ' ' . $object['ref'] );
			$results[ $object['kind'] . ':' . $object['ref'] ] = $again;
		}
		$files   = AB_MCP_Sync_Media::files( $att );
		$reverse = AB_MCP_Sync_Media::reverse( 0, 100 );
		$alias   = AB_MCP_Sync_Option_Schema::classify( ' ab_it_alias' );
		$base    = AB_MCP_Sync_Safe::upload_basedir();
		self::assertSame( 0, SyncSafeWakeupProbe::$woken, 'No object was made on any way of the takeover.' );

		// And each object locks.
		self::assertContains( '*:php_object', self::locks( $results[ 'post:' . $post ] ), 'The builder marker.' );
		self::assertContains( '*:php_object', self::locks( $results[ 'term:' . $term['term_id'] ] ), 'The term meta.' );
		self::assertContains( '*:php_object', self::locks( $results[ 'menu_item:' . $item ] ), 'The menu item.' );
		self::assertContains( '*:php_object', self::locks( $results[ 'theme_mod:' . $stylesheet ] ), 'The row of theme mods, which does not unpack without its class.' );
		self::assertContains( '*:php_object', self::locks( $results['option:ab_it_alias'] ), 'The row the alias lands on.' );
		self::assertSame( 'unreachable', $alias['status'], 'The alias is settled by name, its value not read.' );
		self::assertNull( $base, 'The uploads settings hold an object: no folder.' );
		self::assertSame( array( 'uploads' => 'php_object' ), array_column( $files['problems'], 'problem', 'role' ), 'The gate refused the uploads folder; nothing was listed.' );
		self::assertFalse( $reverse['complete'] );
		self::assertContains( $att, $reverse['unreadable'] );

		// The way WordPress reads them makes the objects.
		get_option( 'upload_path' );
		self::assertSame( 1, SyncSafeWakeupProbe::$woken, 'The probe counts.' );
	}

	public function testTheAliasLandsOnAnotherRowWithoutItsValueBeingRead(): void {
		self::set_option( ' ab_it_alias', 'harmlos' );
		self::set_option( 'ab_it_alias', serialize( new SyncSafeWakeupProbe() ) );
		wp_cache_flush();
		SyncSafeWakeupProbe::$woken = 0;
		$class                      = AB_MCP_Sync_Option_Schema::classify( ' ab_it_alias', 'harmlos' );
		self::assertSame( 0, SyncSafeWakeupProbe::$woken );
		self::assertSame( 'unreachable', $class['status'] );
		self::assertStringContainsString( 'ab_it_alias', (string) $class['reason'] );
		self::assertSame( 'unclassified', AB_MCP_Sync_Option_Schema::classify( 'ab_it_alias' )['status'] );
		self::assertSame( 'guarded', AB_MCP_Sync_Option_Schema::classify( 'mailserver_pass' )['status'] );
		// A spelling the collation reads as another row lands on that row.
		$other = AB_MCP_Sync_Option_Schema::classify( 'BLOGNAME' );
		self::assertSame( 'unreachable', $other['status'] );
		self::assertStringContainsString( 'blogname', (string) $other['reason'] );
		self::assertSame( 0, SyncSafeWakeupProbe::$woken );
	}

	public function testTheUploadsFolderIsAskedOnlyOverCleanRows(): void {
		$clean = AB_MCP_Sync_Safe::upload_basedir();
		self::assertSame( wp_get_upload_dir()['basedir'], $clean, 'Over clean rows the folder of WordPress.' );
		foreach ( AB_MCP_Sync_Safe::READS['upload_dir'] as $name ) {
			$old = \AB_MCP_IT_Sites::option_row( 'a', $name );
			self::set_option( $name, serialize( new SyncSafeWakeupProbe() ) );
			wp_cache_flush();
			SyncSafeWakeupProbe::$woken = 0;
			self::assertNull( AB_MCP_Sync_Safe::upload_basedir(), $name );
			self::assertSame( 0, SyncSafeWakeupProbe::$woken, $name );
			if ( null === $old ) {
				global $wpdb;
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = BINARY %s", $name ) );
			} else {
				self::set_option( $name, (string) $old['option_value'] );
			}
			wp_cache_flush();
		}
		self::assertSame( $clean, AB_MCP_Sync_Safe::upload_basedir() );
		// Worked out anew each time: a changed setting is the folder now.
		$old = \AB_MCP_IT_Sites::option_row( 'a', 'upload_path' );
		self::set_option( 'upload_path', 'wp-content/anders' );
		wp_cache_flush();
		self::assertStringEndsWith( '/wp-content/anders', (string) AB_MCP_Sync_Safe::upload_basedir() );
		self::set_option( 'upload_path', null === $old ? '' : (string) $old['option_value'] );
		wp_cache_flush();
	}
}
