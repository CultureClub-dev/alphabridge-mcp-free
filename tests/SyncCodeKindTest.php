<?php
/**
 * The kind code (Z8b branch 5; plan E10, 3.1 Z26 and Z38): the engine
 * registered for the files of a plugin and of a theme, and the rights a
 * step of the code asks (AB_MCP_Sync_Code_Kind::rights()).
 *
 * Each case of rights() by its effect to the outside (plan 8.3): the
 * capabilities of the account and the settings of the site are asked through
 * the seam of the class, where a stand-in answers as the test says; what
 * cannot be read (no method named, a question that cannot be asked) refuses.
 * Fault kinds that do not apply here: exit/fatal, time limit, concurrent
 * writer and half written (rights() writes nothing and keeps no state); a
 * Throwable of a hook of the rights runs through to AB_MCP_Sync_Write::core(),
 * which makes no call then (SyncWriteTest).
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Sync_Code_Kind;
use AB_MCP_Sync_Kinds;
use AB_MCP_Sync_Pairs;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WP_Error;

require_once dirname( __DIR__ ) . '/includes/sync/class-sync-code-kind.php';
require_once dirname( __DIR__ ) . '/includes/sync/class-sync-pairs.php';

final class SyncCodeKindTest extends TestCase {

	protected function setUp(): void {
		ab_test_reset();
		AB_MCP_Sync_Kinds::reset();
	}

	protected function tearDown(): void {
		AB_MCP_Sync_Kinds::reset();
		AB_MCP_Sync_Code_Kind::forget();
	}

	/**
	 * A stand-in of the calls: the capabilities the account has, whether
	 * files may be changed, the file system method; it records what was asked.
	 *
	 * @param string[]    $caps   Capabilities the account has.
	 * @param bool|null   $mods   What wp_is_file_mod_allowed() answers.
	 * @param string|null $method The file system method.
	 */
	private static function calls( array $caps, $mods = true, $method = 'direct' ): object {
		$calls = new class() {
			/** @var string[] */
			public $caps = array();
			/** @var bool|null */
			public $mods = true;
			/** @var string|null */
			public $method = 'direct';
			/** @var string[] */
			public $asked = array();
			public function can( $cap ) {
				$this->asked[] = (string) $cap;
				return in_array( $cap, $this->caps, true );
			}
			public function mods_allowed() {
				return $this->mods;
			}
			public function method() {
				return $this->method;
			}
		};
		$calls->caps   = $caps;
		$calls->mods   = $mods;
		$calls->method = $method;
		$seam          = new \ReflectionProperty( AB_MCP_Sync_Code_Kind::class, 'naht_sync_code_kind' );
		if ( PHP_VERSION_ID < 80100 ) {
			$seam->setAccessible( true );
		}
		$seam->setValue( null, $calls );
		return $calls;
	}

	private static function refused( $answer, string $code ): string {
		self::assertInstanceOf( WP_Error::class, $answer );
		self::assertSame( $code, $answer->get_error_code(), $answer->get_error_message() );
		return $answer->get_error_message();
	}

	public function testTheEngineIsTheOneRegisteredUnderTheNameCodeAndMovesTheFilesOfAPluginAndOfATheme(): void {
		self::assertNull( AB_MCP_Sync_Kinds::get( 'code' ) );
		$engine = AB_MCP_Sync_Code_Kind::engine();
		self::assertInstanceOf( AB_MCP_Sync_Code_Kind::class, $engine );
		self::assertSame( $engine, AB_MCP_Sync_Kinds::get( 'code' ), 'Registered, once.' );
		self::assertSame( $engine, AB_MCP_Sync_Code_Kind::engine(), 'Always the one the registry holds.' );
		self::assertSame( 'code', $engine->kind() );
		self::assertSame( 2, $engine->phase(), 'The phase of the code.' );
		// What the table gives the kind code, and nothing else.
		$owned = array();
		foreach ( AB_MCP_Sync_Kinds::OWNERSHIP as $key => $entry ) {
			if ( 'code' === $entry['kind'] && ! isset( $entry['stage'] ) ) {
				$owned[] = $key;
			}
		}
		self::assertSame( array( 'part:plugin:files', 'part:theme:files' ), $owned );
		self::assertSame( $owned, $engine->responsibility() );
		// Z38: rebase() takes the files of a plugin and of a theme from it,
		// no other part, and from no other object of the class.
		self::assertTrue( AB_MCP_Sync_Pairs::responsible( $engine, 'plugin', 'files' ) );
		self::assertTrue( AB_MCP_Sync_Pairs::responsible( $engine, 'theme', 'files' ) );
		self::assertFalse( AB_MCP_Sync_Pairs::responsible( $engine, 'plugin', 'activation' ) );
		self::assertFalse( AB_MCP_Sync_Pairs::responsible( $engine, 'theme_choice', 'activation' ) );
		self::assertFalse( AB_MCP_Sync_Pairs::responsible( new AB_MCP_Sync_Code_Kind(), 'plugin', 'files' ), 'Only the engine registered.' );
	}

	public function testAnotherEngineUnderTheNameCodeIsNotTakenForTheKind(): void {
		$other = $this->createStub( \AB_MCP_Sync_Kind::class );
		$other->method( 'kind' )->willReturn( 'code' );
		$other->method( 'phase' )->willReturn( 2 );
		$other->method( 'responsibility' )->willReturn( array( 'part:plugin:files', 'part:theme:files' ) );
		self::assertTrue( AB_MCP_Sync_Kinds::register( $other ) );
		self::refused( AB_MCP_Sync_Code_Kind::engine(), 'ab_mcp_sync_kind_duplicate' );
	}

	/** @return array<string,array{string,string,string[]}> */
	public static function operations(): array {
		return array(
			'a new plugin'                              => array( 'create', 'plugin:shop', array( 'install_plugins' ) ),
			'a new theme'                               => array( 'create', 'theme:lake', array( 'install_themes' ) ),
			'another version of a plugin'               => array( 'update', 'plugin:shop', array( 'upload_plugins', 'update_plugins' ) ),
			'another version of a theme'                => array( 'update', 'theme:lake', array( 'upload_themes', 'update_themes' ) ),
			'the same version of a plugin, other files' => array( 'edit', 'plugin:shop', array( 'upload_plugins', 'update_plugins', 'edit_plugins' ) ),
			'the same version of a theme, other files'  => array( 'edit', 'theme:lake', array( 'upload_themes', 'update_themes', 'edit_themes' ) ),
			'a plugin of one file'                      => array( 'update', 'plugin:hello.php', array( 'upload_plugins', 'update_plugins' ) ),
		);
	}

	#[DataProvider( 'operations' )]
	public function testEachOperationTakesTheCapabilitiesOfE10AndEachOneMissingRefuses( string $op, string $ref, array $needs ): void {
		$engine = AB_MCP_Sync_Code_Kind::engine();
		$all    = array( 'install_plugins', 'install_themes', 'upload_plugins', 'update_plugins', 'edit_plugins', 'upload_themes', 'update_themes', 'edit_themes' );
		// Exactly these, and no other.
		$calls = self::calls( $needs );
		self::assertTrue( $engine->rights( $op, $ref ) );
		self::assertSame( $needs, $calls->asked, 'Each of them asked, and no other.' );
		// Each one missing refuses, whatever else the account has.
		foreach ( $needs as $missing ) {
			self::calls( array_values( array_diff( $all, array( $missing ) ) ) );
			$message = self::refused( $engine->rights( $op, $ref ), 'ab_mcp_sync_rights' );
			self::assertStringContainsString( '(' . $missing . ';', $message, 'The capability is named.' );
			self::assertStringContainsString( explode( ':', $ref, 2 )[1], $message, 'The unit is named.' );
			self::assertStringContainsString( 'Connect with an account that may', $message, 'With the way.' );
			self::assertStringContainsString( 0 === strpos( $ref, 'theme:' ) ? 'the theme ' : 'the plugin ', $message );
		}
		// An account with nothing.
		self::calls( array() );
		self::refused( $engine->rights( $op, $ref ), 'ab_mcp_sync_rights' );
	}

	public function testASiteThatLetsNoFilesBeChangedOrWritesThemNotDirectlyRefusesBeforeACapabilityIsAsked(): void {
		$engine = AB_MCP_Sync_Code_Kind::engine();
		$caps   = array( 'install_plugins', 'upload_plugins', 'update_plugins', 'edit_plugins' );
		// DISALLOW_FILE_MODS (false), and a question that cannot be asked
		// (null): never taken as allowed.
		foreach ( array( false, null ) as $mods ) {
			$calls   = self::calls( $caps, $mods );
			$message = self::refused( $engine->rights( 'update', 'plugin:shop' ), 'code:file_mods' );
			self::assertStringContainsString( 'DISALLOW_FILE_MODS', $message );
			self::assertStringContainsString( 'Ask the host or the site administrator to lift the setting', $message, 'With the way.' );
			self::assertSame( array(), $calls->asked, 'No capability is asked.' );
		}
		// Another file system method, and none named (unknown).
		foreach ( array( 'ftpext', 'ssh2', '', null ) as $method ) {
			$calls   = self::calls( $caps, true, $method );
			$message = self::refused( $engine->rights( 'update', 'plugin:shop' ), 'code:file_mods' );
			self::assertStringContainsString( 'not direct', $message );
			self::assertStringContainsString( 'make the plan again', $message, 'With the way.' );
			self::assertSame( array(), $calls->asked, 'No capability is asked.' );
		}
		self::calls( $caps );
		self::assertTrue( $engine->rights( 'update', 'plugin:shop' ) );
	}

	public function testAnOperationOrAUnitNotOfTheFormIsRefusedBeforeAnythingIsAsked(): void {
		$engine = AB_MCP_Sync_Code_Kind::engine();
		$calls  = self::calls( array( 'install_plugins', 'upload_plugins', 'update_plugins', 'edit_plugins' ) );
		foreach ( array( array( 'delete', 'plugin:shop' ), array( '', 'plugin:shop' ), array( null, 'plugin:shop' ), array( 'update', 'shop' ), array( 'update', 'plugin:' ), array( 'update', 'mu_plugin:shop' ), array( 'update', 'theme_choice:lake' ), array( 'update', array( 'plugin', 'shop' ) ), array( 'update', null ) ) as $case ) {
			self::refused( $engine->rights( $case[0], $case[1] ), 'ab_mcp_sync_kind_invalid' );
		}
		self::assertSame( array(), $calls->asked );
	}

	public function testTheOtherMethodsOfTheContractAnswerWithTheWayForCode(): void {
		$engine = AB_MCP_Sync_Code_Kind::engine();
		self::assertSame( array(), $engine->fields(), 'It shares no row with another kind.' );
		$answers = array(
			'inventory'    => $engine->inventory( null, null ),
			'canonical'    => $engine->canonical( array() ),
			'export'       => $engine->export( array() ),
			'core_effects' => $engine->core_effects( 'update' ),
			'parts'        => $engine->parts( array() ),
			'backup'       => $engine->backup( array() ),
			'write'        => $engine->write( array() ),
			'read_back'    => $engine->read_back( array() ),
			'restore_part' => $engine->restore_part( array() ),
			'post_write'   => $engine->post_write( array() ),
			'verify'       => $engine->verify( array() ),
		);
		foreach ( $answers as $method => $answer ) {
			$message = self::refused( $answer, 'ab_mcp_sync_kind_no_way' );
			self::assertStringContainsString( $method . '()', $message );
			self::assertMatchesRegularExpression( '/AB_MCP_Sync_[A-Za-z_]+/', $message, 'It names what does it for code.' );
		}
	}
}
