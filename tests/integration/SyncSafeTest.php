<?php
/**
 * The rule of includes/sync/ (raw rows only), measured: objects of a class
 * that counts its __wakeup() stand serialized in the rows WordPress would
 * read on the way (upload_path, upload_url_path, timezone_string and
 * gmt_offset, which current_time() reads, active_plugins, template and
 * stylesheet, which the builders' checks read, the row an alias lands on,
 * the theme mods), in the builder marker of a post, the meta of a term, a
 * menu item and an attachment, and in a stale options cache over clean
 * rows. Then the whole inventory, the canonical form of every object it
 * lists, the file list of the attachment, the reverse map, the uploads
 * folder and the classification of the alias run — and no object is made:
 * 0 calls of __wakeup(), the objects locked instead. Reading the same rows
 * the way WordPress does makes them. The uploads folder is the one
 * wp_upload_dir() gives over the same rows; where code decides it, the
 * attachments lock.
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

/**
 * A stream wrapper that counts the questions the file system functions ask it.
 */
final class SyncSafeProbeStream {
	/** @var int */
	public static $asked = 0;

	/** @var resource|null */
	public $context;

	public function url_stat( $path, $flags ) {
		++self::$asked;
		return false;
	}
}

final class SyncSafeTest extends TestCase {

	protected function setUp(): void {
		AB_MCP_IT_Sites::reset( 'a' );
		wp_set_current_user( (int) AB_MCP_IT_Sites::site( 'a' )['admin']['user_id'] );
	}

	protected function tearDown(): void {
		AB_MCP_IT_Sites::reset( 'a' );
		wp_cache_flush();
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
		foreach ( array( 'upload_path', 'upload_url_path', 'timezone_string', 'gmt_offset', 'active_plugins', 'template', 'stylesheet' ) as $name ) {
			self::set_option( $name, $payload );
		}
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
		$uploads = AB_MCP_Sync_Media::uploads();
		self::assertSame( 0, SyncSafeWakeupProbe::$woken, 'No object was made on any way of the takeover.' );

		// And each object locks.
		self::assertContains( '*:php_object', self::locks( $results[ 'post:' . $post ] ), 'The builder marker.' );
		self::assertContains( '*:php_object', self::locks( $results['option:timezone_string'] ), 'The time zone.' );
		self::assertContains( '*:php_object', self::locks( $results['option:gmt_offset'] ), 'The offset.' );
		self::assertContains( '*:php_object', self::locks( $results[ 'term:' . $term['term_id'] ] ), 'The term meta.' );
		self::assertContains( '*:php_object', self::locks( $results[ 'menu_item:' . $item ] ), 'The menu item.' );
		self::assertContains( '*:php_object', self::locks( $results[ 'theme_mod:' . $stylesheet ] ), 'The row of theme mods, which does not unpack without its class.' );
		self::assertContains( '*:php_object', self::locks( $results['option:ab_it_alias'] ), 'The row the alias lands on.' );
		self::assertSame( 'unreachable', $alias['status'], 'The alias is settled by name, its value not read.' );
		self::assertSame( array( 'dir' => '', 'problem' => 'php_object', 'cause' => 'upload_path' ), $uploads, 'upload_path holds an object: no folder.' );
		self::assertSame( array( 'uploads' => 'php_object' ), array_column( $files['problems'], 'problem', 'role' ), 'No folder; nothing was listed.' );
		self::assertFalse( $reverse['complete'] );
		self::assertContains( $att, $reverse['unreadable'] );

		// The way WordPress reads them makes the objects.
		get_option( 'upload_path' );
		self::assertSame( 1, SyncSafeWakeupProbe::$woken, 'The probe counts.' );
		try {
			current_time( 'mysql' );
		} catch ( \Throwable $e ) {
			// The object is made before the arithmetic refuses it.
			unset( $e );
		}
		self::assertGreaterThan( 1, SyncSafeWakeupProbe::$woken, 'current_time() reads the time zone.' );
	}

	public function testAStaleOptionsCacheWithObjectsOverCleanRowsWakesNothing(): void {
		$payload = serialize( new SyncSafeWakeupProbe() );
		$post    = wp_insert_post( array( 'post_title' => 'Seite', 'post_status' => 'publish', 'post_type' => 'page', 'post_content' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' ) );
		$att     = wp_insert_post( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => 'Bild', 'post_mime_type' => 'image/jpeg' ) );
		add_post_meta( $att, '_wp_attached_file', '2026/11/bild.jpg' );
		add_post_meta( $post, '_elementor_edit_mode', 'builder' );
		// Every autoloaded option in the cache an object, and the options
		// the way of WordPress would read one by one: the rows stay clean.
		$stale = array();
		foreach ( wp_load_alloptions() as $name => $value ) {
			$stale[ $name ] = $payload;
		}
		wp_cache_flush();
		wp_cache_set( 'alloptions', $stale, 'options' );
		foreach ( array( 'upload_path', 'upload_url_path', 'timezone_string', 'gmt_offset', 'active_plugins', 'active_sitewide_plugins', 'template', 'stylesheet', 'uploads_use_yearmonth_folders', 'siteurl', 'home' ) as $name ) {
			wp_cache_set( $name, $payload, 'options' );
		}
		wp_cache_set( $post, array( '_elementor_edit_mode' => array( $payload ) ), 'post_meta' );
		SyncSafeWakeupProbe::$woken = 0;

		$all = AB_MCP_Sync_Inventory::run( AB_MCP_Sync_Inventory::source( AB_MCP_IT_Sync_Steps::addresses() ) );
		self::assertIsArray( $all );
		foreach ( $all['objects'] as $object ) {
			self::assertIsArray( self::canon( $object['kind'], $object['ref'] ), $object['kind'] . ' ' . $object['ref'] );
		}
		$files   = AB_MCP_Sync_Media::files( $att );
		$reverse = AB_MCP_Sync_Media::reverse( 0, 100 );
		$uploads = AB_MCP_Sync_Media::uploads();
		AB_MCP_Sync_Option_Schema::classify( 'blogname' );
		self::assertSame( 0, SyncSafeWakeupProbe::$woken, 'The rows are read, never the cache.' );

		// What the rows say, not the cache.
		self::assertSame( '', $uploads['problem'] );
		self::assertNotContains( 'uploads', array_column( $files['problems'], 'role' ) );
		self::assertTrue( $reverse['complete'] );
		self::assertSame( array( 'elementor' ), array_column( \AB_MCP_Sync_Builder_Lock::locked( \AB_MCP_Sync_Rows::post( $post ), \AB_MCP_Sync_Rows::meta( 'post', $post ) ), 'id' ), 'The marker of the row.' );

		get_option( 'blogname' );
		self::assertSame( 1, SyncSafeWakeupProbe::$woken, 'The way of WordPress reads the cache.' );
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

	public function testTheUploadsFolderIsTheOneWordPressWorksOutOverTheSameRows(): void {
		$old = \AB_MCP_IT_Sites::option_row( 'a', 'upload_path' );
		foreach ( array( '', 'wp-content/uploads', 'wp-content/anders', '  wp-content/gepolstert  ', ABSPATH . 'absolut', '/tmp/ab-it-anderswo', 's:17:"wp-content/serial";', 'a:1:{i:0;i:1;i:1;i:2;}', 'i:7;' ) as $value ) {
			self::set_option( 'upload_path', $value );
			wp_cache_flush();
			$want = wp_upload_dir( null, false, true )['basedir'];
			self::assertSame( array( 'dir' => $want, 'problem' => '', 'cause' => '' ), AB_MCP_Sync_Media::uploads(), $value );
		}
		self::assertStringEndsWith( '/7', $want, 'The number as text, as WordPress reads it.' );
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", 'upload_path' ) );
		wp_cache_flush();
		self::assertSame( wp_upload_dir( null, false, true )['basedir'], AB_MCP_Sync_Media::uploads()['dir'], 'No row.' );

		// What does not unpack to a text names no folder.
		$cases = array(
			serialize( new SyncSafeWakeupProbe() ) => 'php_object',
			'a:1:{i:0;s:1:"x";}'                    => 'unreadable',
			'a:1:{i:0;s:1:"x"'                      => 'unsafe', // Not serialized; "a:" reads as a scheme.
			's:9:"kaputt";'                         => 'php_object',
			"wp-content/\0nul"                     => 'unsafe',
			'wp-content\\anders'                    => 'unsafe',
			'C:/uploads'                            => 'unsafe',
		);
		foreach ( $cases as $value => $problem ) {
			self::set_option( 'upload_path', (string) $value );
			wp_cache_flush();
			SyncSafeWakeupProbe::$woken = 0;
			self::assertSame( $problem, AB_MCP_Sync_Media::uploads()['problem'], (string) $value );
			self::assertSame( 0, SyncSafeWakeupProbe::$woken );
		}
		self::set_option( 'upload_path', null === $old ? '' : (string) $old['option_value'] );
		wp_cache_flush();

		// The rows the basedir does not need may hold anything.
		$clean = AB_MCP_Sync_Media::uploads();
		foreach ( array( 'timezone_string', 'gmt_offset', 'uploads_use_yearmonth_folders', 'upload_url_path', 'siteurl' ) as $name ) {
			self::set_option( $name, serialize( new SyncSafeWakeupProbe() ) );
		}
		wp_cache_flush();
		SyncSafeWakeupProbe::$woken = 0;
		self::assertSame( $clean, AB_MCP_Sync_Media::uploads() );
		self::assertSame( 0, SyncSafeWakeupProbe::$woken );
	}

	public function testAnUploadPathOfAStreamWrapperReachesNoWrapper(): void {
		stream_wrapper_register( 'reviewprobe', SyncSafeProbeStream::class );
		SyncSafeProbeStream::$asked = 0;
		try {
			self::set_option( 'upload_path', 'reviewprobe://host/uploads' );
			wp_cache_flush();
			$uploads = AB_MCP_Sync_Media::uploads();
			$asked   = SyncSafeProbeStream::$asked;
		} finally {
			stream_wrapper_unregister( 'reviewprobe' );
		}
		self::assertSame( 0, $asked, 'No url_stat() before the lock.' );
		self::assertSame( array( 'dir' => '', 'problem' => 'unsafe', 'cause' => 'upload_path' ), $uploads );
	}

	public function testWhereCodeDecidesTheUploadsFolderTheAttachmentsLock(): void {
		$att = wp_insert_post( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => 'Bild', 'post_mime_type' => 'image/jpeg' ) );
		add_post_meta( $att, '_wp_attached_file', '2026/11/bild.jpg' );
		$offload = static function ( $uploads ) {
			$uploads['basedir'] = 's3://bucket';
			return $uploads;
		};
		$path    = static function () {
			return 'wp-content/anderswo';
		};
		foreach ( array( 'upload_dir' => $offload, 'option_upload_path' => $path, 'pre_option_upload_path' => $path, 'alloptions' => static fn( $all ) => $all ) as $hook => $callback ) {
			add_filter( $hook, $callback );
			self::assertSame( array( 'dir' => '', 'problem' => 'by_code', 'cause' => 'filter:' . $hook ), AB_MCP_Sync_Media::uploads(), $hook );
			$acc = \AB_MCP_Sync_Canon::acc();
			AB_MCP_Sync_Media::note( $acc, AB_MCP_Sync_Media::files( $att ) );
			self::assertSame( array( array( 'attachment:uploads_by_code', 'uploads:filter:' . $hook ) ), array_map( static fn( $l ) => array( $l['key'], $l['at'] ), $acc['locks'] ), $hook );
			self::assertFalse( AB_MCP_Sync_Media::reverse( 0, 100 )['complete'], $hook );
			remove_filter( $hook, $callback );
		}
		self::assertSame( '', AB_MCP_Sync_Media::uploads()['problem'], 'Without the filters the rows decide.' );
		$acc = \AB_MCP_Sync_Canon::acc();
		AB_MCP_Sync_Media::note( $acc, AB_MCP_Sync_Media::files( $att ) );
		self::assertNotContains( 'attachment:uploads_by_code', array_column( $acc['locks'], 'key' ) );
	}
}
