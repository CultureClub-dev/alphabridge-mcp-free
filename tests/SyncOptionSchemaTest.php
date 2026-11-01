<?php
/**
 * The core option schema (AB_MCP_Sync_Option_Schema, Z8 decision 27): its
 * entries are well formed and none is left out elsewhere (group B, another
 * owner, the option guard); the rule of each type; the canonical value by
 * type, references as markers, a value off its rule locked. That CORE is
 * what populate_options() of WordPress 7.1.3 creates is
 * tests/integration/SyncSchemasTest.php.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Option_Guard;
use AB_MCP_Sync_Canon;
use AB_MCP_Sync_Exclusions;
use AB_MCP_Sync_Kinds;
use AB_MCP_Sync_Option_Name;
use AB_MCP_Sync_Option_Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/sync/class-sync-option-schema.php';

final class SyncOptionSchemaTest extends TestCase {

	private const TYPES = array( 'int', 'flag', 'choice', 'text', 'number', 'rewrite', 'ref', 'refs' );

	public function testEveryEntryIsWellFormedAndNotLeftOutElsewhere(): void {
		self::assertGreaterThan( 70, count( AB_MCP_Sync_Option_Schema::CORE ) );
		foreach ( AB_MCP_Sync_Option_Schema::CORE as $name => $schema ) {
			self::assertContains( $schema['type'], self::TYPES, $name );
			if ( in_array( $schema['type'], array( 'ref', 'refs' ), true ) ) {
				self::assertContains( $schema['space'], array( 'post', 'term' ), $name );
			}
			if ( 'choice' === $schema['type'] ) {
				self::assertNotEmpty( $schema['values'], $name );
			}
			self::assertFalse( AB_MCP_Sync_Exclusions::option( $name ), "$name is not of group B." );
			$owner = AB_MCP_Sync_Kinds::option( $name, 'wp_' );
			self::assertSame( array( 'option', 'z8' ), array( $owner['kind'], $owner['status'] ), "$name belongs to the option kind." );
			self::assertFalse( AB_MCP_Option_Guard::option_write_refused( $name ), "The guard lets $name through." );
		}
	}

	public static function rules(): array {
		return array(
			'int'           => array( 'posts_per_page', array( '10', '0' ), array( '-1', '1.5', '', 'zehn' ) ),
			'flag'          => array( 'use_smilies', array( '', '0', '1' ), array( '2', 'on', 'true' ) ),
			'choice'        => array( 'show_on_front', array( 'posts', 'page' ), array( 'Page', '', 'home' ) ),
			'number'        => array( 'gmt_offset', array( '0', '5.5', '-3' ), array( 'UTC+1', '' ) ),
			'ref'           => array( 'page_on_front', array( '0', '12' ), array( '-1', 'home', '' ) ),
			'refs'          => array( 'sticky_posts', array( 'a:0:{}', serialize( array( 3, 7 ) ), serialize( array( '3' ) ) ), array( '3,7', serialize( array( 'a' => 3 ) ), serialize( array( 'x' ) ), 'O:8:"stdClass":0:{}' ) ),
			'text'          => array( 'blogname', array( '', 'Meine Site', '0' ), array() ),
		);
	}

	#[DataProvider( 'rules' )]
	public function testEachTypeHasItsRule( string $name, array $fit, array $off ): void {
		$schema = AB_MCP_Sync_Option_Schema::CORE[ $name ];
		foreach ( $fit as $raw ) {
			self::assertTrue( AB_MCP_Sync_Option_Schema::fits( $schema, $raw ), "$name: $raw" );
		}
		foreach ( $off as $raw ) {
			self::assertFalse( AB_MCP_Sync_Option_Schema::fits( $schema, $raw ), "$name: $raw" );
		}
	}

	public function testTheCanonicalValueByType(): void {
		$ctx = AB_MCP_Sync_Canon::context( array( 'https://staging.test' ) );
		$acc = AB_MCP_Sync_Canon::acc();
		self::assertSame( array( '~ref' => 'post', 'id' => 12 ), AB_MCP_Sync_Option_Schema::canonical( AB_MCP_Sync_Option_Schema::CORE['page_on_front'], '12', $ctx, $acc ) );
		self::assertSame( '0', AB_MCP_Sync_Option_Schema::canonical( AB_MCP_Sync_Option_Schema::CORE['page_on_front'], '0', $ctx, $acc ) );
		self::assertSame( array( '~ref' => 'term', 'id' => 4 ), AB_MCP_Sync_Option_Schema::canonical( AB_MCP_Sync_Option_Schema::CORE['default_category'], '4', $ctx, $acc ) );
		self::assertSame( array( '~php' => array( array( '~ref' => 'post', 'id' => 3 ), array( '~ref' => 'post', 'id' => 7, 'text' => true ) ) ), AB_MCP_Sync_Option_Schema::canonical( AB_MCP_Sync_Option_Schema::CORE['sticky_posts'], serialize( array( 3, '7' ) ), $ctx, $acc ) );
		self::assertSame( array( 'option_value', 'option_value', 'option_value.0', 'option_value.1' ), array_column( $acc['refs'], 'at' ) );
		self::assertSame( array( '~s' => array( 'Auf ', array( '~addr' => 'plain' ), '/' ) ), AB_MCP_Sync_Option_Schema::canonical( AB_MCP_Sync_Option_Schema::CORE['blogdescription'], 'Auf https://staging.test/', $ctx, $acc ) );
		self::assertSame( array(), $acc['locks'] );

		self::assertSame( 'home', AB_MCP_Sync_Option_Schema::canonical( AB_MCP_Sync_Option_Schema::CORE['page_on_front'], 'home', $ctx, $acc ) );
		self::assertSame( array( 'option:off_schema' ), array_column( $acc['locks'], 'key' ) );
	}

	public function testTheRefusalAsksWhatTheGuardAsksWithoutItsTranslatedMessage(): void {
		$names = array( 'blogname', 'blogdescription', 'siteurl', 'home', 'active_plugins', 'mailserver_pass', 'my_api_key', 'acme_token', 'ab_mcp_pro_deploy', 'ab_mcp_pro_déploy', 'actiue_plugins', 'auth_salt', 'wp_user_roles', "blog\u{200B}name", str_repeat( 'a', 192 ), str_repeat( 'a', 191 ), 'widget_text', 'theme_mods_twentytwentyfive' );
		$refused = 0;
		foreach ( $names as $name ) {
			$guard = is_wp_error( AB_MCP_Option_Guard::option_write_refused( $name ) );
			self::assertSame( $guard, AB_MCP_Sync_Option_Name::refused( $name ), $name );
			$refused += $guard ? 1 : 0;
		}
		self::assertGreaterThan( 5, $refused, 'Both answers are asked.' );
		self::assertLessThan( count( $names ), $refused );
	}
}
