<?php
/**
 * The schemas of the canonical form against WordPress 7.1.3 itself:
 *
 * - AB_MCP_Sync_Option_Schema::CORE against populate_options(), run into an
 *   empty copy of the options table: every name it creates is in CORE or
 *   left out for its reason (group B, another owner, the option guard),
 *   CORE names nothing else, and each default keeps its rule;
 * - AB_MCP_Sync_Refs::PLAIN_NUMBERS, ::BLOCKS and ::UNMAPPED against the
 *   block.json of every core block: each numeric attribute is in exactly one
 *   of them, and none names an attribute the core does not have;
 * - the core's parser and serializer round-trip what the rewriter writes.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests\Integration;

use AB_MCP_IT_Sites;
use AB_MCP_Option_Guard;
use AB_MCP_Sync_Exclusions;
use AB_MCP_Sync_Kinds;
use AB_MCP_Sync_Option_Schema;
use AB_MCP_Sync_Refs;
use PHPUnit\Framework\TestCase;

final class SyncSchemasTest extends TestCase {

	public static function setUpBeforeClass(): void {
		AB_MCP_IT_Sites::reset( 'a' );
	}

	public static function tearDownAfterClass(): void {
		AB_MCP_IT_Sites::reset( 'a' );
	}

	/** The rows populate_options() writes into an empty options table. */
	private static function populated(): array {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		require_once ABSPATH . 'wp-admin/includes/schema.php';
		$copy  = $wpdb->prefix . 'abit_options';
		$saved = $wpdb->options;
		$wpdb->query( "DROP TABLE IF EXISTS `$copy`" );
		self::assertNotFalse( $wpdb->query( "CREATE TABLE `$copy` LIKE `$saved`" ) );
		$wpdb->options = $copy;
		wp_cache_flush();
		try {
			populate_options();
			$rows = $wpdb->get_results( "SELECT option_name, option_value FROM `$copy` ORDER BY option_id", ARRAY_A );
		} finally {
			$wpdb->options = $saved;
			wp_cache_flush();
			$wpdb->query( "DROP TABLE IF EXISTS `$copy`" );
		}
		return array_column( $rows, 'option_value', 'option_name' );
	}

	public function testTheCoreOptionSchemaIsWhatPopulateOptionsCreatesWithoutTheOthers(): void {
		self::assertSame( '7.1.3', get_bloginfo( 'version' ) );
		$created = self::populated();
		self::assertGreaterThan( 90, count( $created ), 'populate_options() ran into the copy.' );

		$reasons = array();
		foreach ( $created as $name => $value ) {
			$why = array();
			if ( isset( AB_MCP_Sync_Option_Schema::CORE[ $name ] ) ) {
				$why[] = 'schema';
			}
			if ( AB_MCP_Sync_Exclusions::option( $name ) ) {
				$why[] = 'group B';
			}
			$owner = AB_MCP_Sync_Kinds::option( $name );
			if ( 'excluded' !== $owner['status'] && 'option' !== $owner['kind'] ) {
				$why[] = 'owner ' . $owner['key'];
			}
			if ( is_wp_error( AB_MCP_Option_Guard::option_write_refused( $name ) ) ) {
				$why[] = 'guard';
			}
			self::assertNotSame( array(), $why, "$name, created by populate_options(), is neither in the schema nor left out for a reason." );
			if ( in_array( 'schema', $why, true ) ) {
				self::assertSame( array( 'schema' ), $why, "$name is in the schema and left out at once." );
				self::assertTrue( AB_MCP_Sync_Option_Schema::fits( AB_MCP_Sync_Option_Schema::CORE[ $name ], (string) $value ), "The default of $name keeps the rule of its type." );
				self::assertSame( 'core', AB_MCP_Sync_Option_Schema::classify( $name )['status'], $name );
			}
			$reasons[ $name ] = $why;
		}
		foreach ( array_keys( AB_MCP_Sync_Option_Schema::CORE ) as $name ) {
			self::assertArrayHasKey( $name, $created, "$name of the schema is an option populate_options() creates." );
		}
		// The ones the guard refuses and nothing else leaves out: mail.
		foreach ( array( 'mailserver_url', 'mailserver_login', 'mailserver_pass', 'mailserver_port' ) as $name ) {
			self::assertSame( array( 'guard' ), $reasons[ $name ], $name );
			self::assertSame( 'guarded', AB_MCP_Sync_Option_Schema::classify( $name )['status'], $name );
		}
		self::assertSame( array( 'owner option:widget_*' ), $reasons['widget_text'] );
		self::assertContains( 'group B', $reasons['initial_db_version'] );
	}

	/**
	 * Each value of a choice, with the place of the core that writes it
	 * (file, line, text on that line).
	 */
	private const CHOICES = array(
		'default_comment_status' => array(
			'open'   => array( 'wp-admin/options-discussion.php', 59, 'name="default_comment_status" type="checkbox" id="default_comment_status" value="open"' ),
			'closed' => array( 'wp-includes/formatting.php', 4984, "\$value = 'closed';" ),
		),
		'default_ping_status'    => array(
			'open'   => array( 'wp-admin/options-discussion.php', 55, 'name="default_ping_status" type="checkbox" id="default_ping_status" value="open"' ),
			'closed' => array( 'wp-includes/formatting.php', 4984, "\$value = 'closed';" ),
		),
		'show_on_front'          => array(
			'posts' => array( 'wp-admin/options-reading.php', 93, 'name="show_on_front" type="radio" value="posts"' ),
			'page'  => array( 'wp-admin/options-reading.php', 98, 'name="show_on_front" type="radio" value="page"' ),
		),
		'avatar_rating'          => array(
			'G'  => array( 'wp-admin/options-discussion.php', 241, "'G'  => __(" ),
			'PG' => array( 'wp-admin/options-discussion.php', 243, "'PG' => __(" ),
			'R'  => array( 'wp-admin/options-discussion.php', 245, "'R'  => __(" ),
			'X'  => array( 'wp-admin/options-discussion.php', 247, "'X'  => __(" ),
		),
		'default_comments_page'  => array(
			'newest' => array( 'wp-admin/options-discussion.php', 138, '<option value="newest"' ),
			'oldest' => array( 'wp-admin/options-discussion.php', 139, '<option value="oldest"' ),
		),
		'comment_order'          => array(
			'asc'  => array( 'wp-admin/options-discussion.php', 145, '<option value="asc"' ),
			'desc' => array( 'wp-admin/options-discussion.php', 146, '<option value="desc"' ),
		),
		'auto_update_core_dev'   => array(
			'enabled' => array( 'wp-admin/includes/schema.php', 550, "'auto_update_core_dev'            => 'enabled'," ),
		),
		'auto_update_core_minor' => array(
			'enabled' => array( 'wp-admin/includes/schema.php', 551, "'auto_update_core_minor'          => 'enabled'," ),
		),
		'auto_update_core_major' => array(
			'enabled'  => array( 'wp-admin/update-core.php', 1311, "update_site_option( 'auto_update_core_major', 'enabled' );" ),
			'disabled' => array( 'wp-admin/update-core.php', 1314, "update_site_option( 'auto_update_core_major', 'disabled' );" ),
			'unset'    => array( 'wp-admin/includes/upgrade.php', 2279, "update_option( 'auto_update_core_major', 'unset' );" ),
		),
	);

	public function testEachValueOfAChoiceIsOneTheCoreWrites(): void {
		$choices = array();
		foreach ( AB_MCP_Sync_Option_Schema::CORE as $name => $schema ) {
			if ( 'choice' === $schema['type'] ) {
				$choices[ $name ] = $schema['values'];
			}
		}
		self::assertSame( array_keys( self::CHOICES ), array_keys( $choices ), 'A list of places for each choice of the schema.' );
		foreach ( self::CHOICES as $name => $values ) {
			self::assertSame( array_keys( $values ), $choices[ $name ], "$name: exactly the values the core writes." );
			foreach ( $values as $value => list( $file, $line, $text ) ) {
				$lines = file( ABSPATH . $file );
				self::assertStringContainsString( $text, (string) ( $lines[ $line - 1 ] ?? '' ), "$name $value: $file:$line" );
			}
		}
		// The checkbox left empty is stored as closed, never as ''.
		update_option( 'default_comment_status', '' );
		self::assertSame( 'closed', \AB_MCP_IT_Sites::option_row( 'a', 'default_comment_status' )['option_value'] );
	}

	public function testEveryOtherOptionIsLockedAsUnclassified(): void {
		add_option( 'ab_it_plugin_setting', 'x' );
		$class = AB_MCP_Sync_Option_Schema::classify( 'ab_it_plugin_setting' );
		self::assertSame( 'unclassified', $class['status'] );
		self::assertSame( 'option:unclassified', $class['key'] );
		self::assertSame( 'Z8d', $class['stage'] );
		// A row WordPress does not read under its name.
		global $wpdb;
		$wpdb->insert( $wpdb->options, array( 'option_name' => 'blacklist_keys', 'option_value' => 'alt', 'autoload' => 'off' ) );
		self::assertSame( 'unreachable', AB_MCP_Sync_Option_Schema::classify( 'blacklist_keys' )['status'] );
		self::assertSame( 'excluded', AB_MCP_Sync_Option_Schema::classify( 'cron' )['status'] );
		self::assertSame( 'owner', AB_MCP_Sync_Option_Schema::classify( 'sidebars_widgets' )['status'] );
		foreach ( \AB_MCP_Sync_Db_Kinds::all() as $kind ) {
			if ( 'option' === $kind->kind() ) {
				$source = \AB_MCP_Sync_Inventory::source( array() );
				self::assertInstanceOf( \WP_Error::class, $kind->canonical( array( 'ref' => 'sidebars_widgets', 'source' => $source ) ), 'A row of the widget kind is no object of the option kind.' );
				self::assertSame( 'locked', \AB_MCP_Sync_Inventory::entry( $kind->canonical( array( 'ref' => 'active_plugins', 'source' => $source ) ) )['status'], 'A row whose owner is locked is listed with its lock.' );
			}
		}
	}

	public function testTheNumbersOfTheCoreBlocksAreEachInOneList(): void {
		$files = glob( ABSPATH . WPINC . '/blocks/*/block.json' );
		self::assertGreaterThan( 100, count( $files ) );
		$numeric = array();
		$all     = array();
		foreach ( $files as $file ) {
			$json = wp_json_file_decode( $file, array( 'associative' => true ) );
			foreach ( isset( $json['attributes'] ) ? $json['attributes'] : array() as $name => $attr ) {
				$all[ $json['name'] ][] = $name;
				if ( array_intersect( (array) ( $attr['type'] ?? array() ), array( 'number', 'integer' ) ) ) {
					$numeric[ $json['name'] ][] = $name;
				}
			}
		}
		foreach ( $numeric as $block => $names ) {
			foreach ( $names as $name ) {
				$in = array();
				if ( in_array( $name, AB_MCP_Sync_Refs::PLAIN_NUMBERS[ $block ] ?? array(), true ) ) {
					$in[] = 'plain';
				}
				if ( isset( AB_MCP_Sync_Refs::BLOCKS[ $block ][ $name ] ) ) {
					$in[] = 'reference';
				}
				if ( in_array( $name, AB_MCP_Sync_Refs::UNMAPPED[ $block ] ?? array(), true ) ) {
					$in[] = 'unmapped';
				}
				self::assertCount( 1, $in, "$block $name is in exactly one list: " . implode( ', ', $in ) );
			}
		}
		foreach ( array( 'PLAIN_NUMBERS' => AB_MCP_Sync_Refs::PLAIN_NUMBERS, 'UNMAPPED' => AB_MCP_Sync_Refs::UNMAPPED ) as $list => $blocks ) {
			foreach ( $blocks as $block => $names ) {
				foreach ( $names as $name ) {
					self::assertContains( $name, $numeric[ $block ] ?? array(), "$list: $block $name is a number of the core." );
				}
			}
		}
		foreach ( AB_MCP_Sync_Refs::BLOCKS as $block => $paths ) {
			foreach ( array_keys( $paths ) as $path ) {
				self::assertContains( explode( '.', $path )[0], $all[ $block ] ?? array(), "BLOCKS: $block $path is an attribute of the core." );
			}
		}
		// The query attribute's default holds the paging QUERY_PLAIN names.
		$query = wp_json_file_decode( ABSPATH . WPINC . '/blocks/query/block.json', array( 'associative' => true ) );
		foreach ( AB_MCP_Sync_Refs::QUERY_PLAIN as $key ) {
			self::assertArrayHasKey( $key, $query['attributes']['query']['default'] );
		}
		foreach ( array( 'author', 'taxQuery', 'exclude', 'parents' ) as $key ) {
			self::assertArrayHasKey( $key, $query['attributes']['query']['default'] );
		}
	}

	public function testTheCitationsOfTheSchemasSayWhatTheySay(): void {
		foreach ( array(
			array( 'wp-includes/js/dist/block-library.js', 23880, 'cover/save.mjs' ),
			array( 'wp-includes/js/dist/block-library.js', 23940, 'wp-image-${id}' ),
			array( 'wp-includes/js/dist/block-library.js', 38039, 'image/save.mjs' ),
			array( 'wp-includes/js/dist/block-library.js', 38075, 'wp-image-${id}' ),
			array( 'wp-includes/js/dist/block-library.js', 43422, 'media-text/save.mjs' ),
			array( 'wp-includes/js/dist/block-library.js', 43448, 'wp-image-${mediaId}' ),
			array( 'wp-includes/js/dist/block-library.js', 28656, '"data-id": image.id' ),
			array( 'wp-includes/js/dist/block-library.js', 28659, 'wp-image-${image.id}' ),
			array( 'wp-includes/media.php', 408, "' wp-image-' . \$id" ),
			array( 'wp-includes/media.php', 2628, 'function img_caption_shortcode( $attr, $content = \'\' ) {' ),
			array( 'wp-includes/media.php', 2828, 'function gallery_shortcode( $attr ) {' ),
			array( 'wp-includes/media.php', 3797, "'width'    => 640," ),
			array( 'wp-includes/media.php', 3798, "'height'   => 360," ),
			array( 'wp-includes/class-wp-embed.php', 211, 'wp_embed_defaults( $url )' ),
			array( 'wp-includes/media.php', 2834, "! empty( \$attr['ids'] )" ),
			array( 'wp-includes/media.php', 3201, "! empty( \$attr['ids'] )" ),
			array( 'wp-admin/includes/media.php', 220, "'attachment_' . \$id" ),
			array( 'wp-includes/blocks.php', 3038, "explode( ',', \$block->context['query']['author'] )" ),
			array( 'wp-includes/blocks.php', 2906, '"taxQuery":{"category":[4]}' ),
			array( 'wp-includes/blocks.php', 2855, "\$block->context['query']['exclude']" ),
			array( 'wp-includes/blocks.php', 3047, "\$block->context['query']['parents']" ),
		) as list( $file, $line, $text ) ) {
			$lines = file( ABSPATH . $file );
			self::assertStringContainsString( $text, (string) ( $lines[ $line - 1 ] ?? '' ), "$file:$line" );
		}
	}

	public function testTheRewriterWritesWhatTheCoreParsesAgain(): void {
		$content = '<!-- wp:image {"id":12,"sizeSlug":"large"} -->' . "\n" . '<figure class="wp-block-image size-large"><img src="http://staging.test/wp-content/uploads/a.jpg" class="wp-image-12"/></figure>' . "\n" . '<!-- /wp:image -->';
		$plan    = AB_MCP_Sync_Refs::plan( array( 'post' => array( 12 => 340 ) ), array( 'http://staging.test' => 'https://live.test' ) );
		$out     = AB_MCP_Sync_Refs::rewrite_content( $content, $plan );
		self::assertSame( array(), $out['missing'] );
		self::assertSame( '<!-- wp:image {"id":340,"sizeSlug":"large"} -->' . "\n" . '<figure class="wp-block-image size-large"><img src="https://live.test/wp-content/uploads/a.jpg" class="wp-image-340"/></figure>' . "\n" . '<!-- /wp:image -->', $out['content'] );
		$blocks = parse_blocks( $out['content'] );
		self::assertSame( 340, $blocks[0]['attrs']['id'] );
	}
}
