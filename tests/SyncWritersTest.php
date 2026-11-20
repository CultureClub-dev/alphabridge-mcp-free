<?php
/**
 * The registration of the write side (AB_MCP_Sync_Writers, the seam, first
 * step T1): U1 the contract as a table of cases, each a series of
 * registrations, each refusal with its code and nothing registered; U2 the
 * table of equality, the writers this plugin registers as the engine loads
 * against AB_MCP_Sync_Engine::WRITERS, also in a fresh process, and
 * AB_MCP_Sync_Engine::writer() with a subtype asking the registration (T2),
 * AB_MCP_Sync_Writers::all() the table of the registration (T6).
 *
 * The registrations made as the engine loads are kept before each case and
 * put back after it (reflection), for the other tests of the process.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Sync_Engine;
use AB_MCP_Sync_Writers;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use WP_Error;

require_once dirname( __DIR__ ) . '/includes/sync/class-sync-writers.php';
require_once dirname( __DIR__ ) . '/includes/sync/class-sync-engine.php';

final class SyncWritersTest extends TestCase {

	/** AB_MCP_Sync_Engine::WRITERS at f69155e, written out. */
	private const CLASSES = array(
		'post'        => 'AB_MCP_Sync_Post_Writer',
		'term'        => 'AB_MCP_Sync_Term_Writer',
		'attachment'  => 'AB_MCP_Sync_Attachment_Writer',
		'menu'        => 'AB_MCP_Sync_Menu_Writer',
		'menu_item'   => 'AB_MCP_Sync_Menu_Item_Writer',
		'widget_area' => 'AB_MCP_Sync_Widget_Area_Writer',
		'custom_css'  => 'AB_MCP_Sync_Css_Writer',
		'option'      => 'AB_MCP_Sync_Option_Writer',
	);

	/** The subtypes plan and journal carry: Post_Writer::TYPES, Term_Writer::TAXONOMIES, the fixed ones, '' and a foreign one. */
	private const SUBTYPES = array( 'post', 'page', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'category', 'post_tag', 'wp_pattern_category', 'nav_menu', 'nav_menu_item', 'attachment', 'custom_css', 'widget_area', '', 'no_such_type' );

	private const PW = 'AB_MCP_Sync_Post_Writer';
	private const TW = 'AB_MCP_Sync_Term_Writer';
	private const MW = 'AB_MCP_Sync_Menu_Writer';

	/** @var array|null The table as the engine registered it. */
	private $kept = null;

	private static function table(): array {
		return ( new ReflectionProperty( AB_MCP_Sync_Writers::class, 'writers' ) )->getValue();
	}

	protected function setUp(): void {
		$this->kept = self::table();
		AB_MCP_Sync_Writers::reset();
	}

	protected function tearDown(): void {
		$this->restore();
	}

	private function restore(): void {
		( new ReflectionProperty( AB_MCP_Sync_Writers::class, 'writers' ) )->setValue( null, $this->kept );
	}

	/**
	 * Case => registrations in order (kind, subtypes, class, true or the
	 * code), then answers (kind, subtype or null for whole(), class or null).
	 */
	public static function cases(): array {
		$w = AB_MCP_Sync_Writers::WHOLE;
		return array(
			'one whole kind'                          => array( array( array( 'post', $w, self::PW, true ) ), array( array( 'post', 'page', self::PW ), array( 'post', '', self::PW ), array( 'post', null, self::PW ), array( 'term', 'category', null ), array( 'term', null, null ) ) ),
			'only post with post and page (D2)'       => array( array( array( 'post', array( 'post', 'page' ), self::PW, true ) ), array( array( 'post', 'page', self::PW ), array( 'post', 'post', self::PW ), array( 'post', 'wp_block', null ), array( 'post', '', null ), array( 'post', null, null ) ) ),
			'other subtypes from another class'       => array( array( array( 'post', array( 'post', 'page' ), self::PW, true ), array( 'post', array( 'wp_block' ), self::TW, true ) ), array( array( 'post', 'wp_block', self::TW ), array( 'post', 'page', self::PW ), array( 'post', null, null ) ) ),
			'the whole kind twice'                    => array( array( array( 'post', $w, self::PW, true ), array( 'post', $w, self::TW, 'ab_mcp_sync_writer_duplicate' ) ), array( array( 'post', 'page', self::PW ), array( 'post', null, self::PW ) ) ),
			'a subtype twice'                         => array( array( array( 'post', array( 'page' ), self::PW, true ), array( 'post', array( 'wp_block', 'page' ), self::TW, 'ab_mcp_sync_writer_duplicate' ) ), array( array( 'post', 'page', self::PW ), array( 'post', 'wp_block', null ) ) ),
			'a subtype twice in one registration'     => array( array( array( 'post', array( 'page', 'page' ), self::PW, 'ab_mcp_sync_writer_duplicate' ) ), array( array( 'post', 'page', null ) ) ),
			'whole after a subtype'                   => array( array( array( 'post', array( 'page' ), self::PW, true ), array( 'post', $w, self::TW, 'ab_mcp_sync_writer_whole' ) ), array( array( 'post', null, null ), array( 'post', 'post', null ), array( 'post', 'page', self::PW ) ) ),
			'a subtype after whole'                   => array( array( array( 'post', $w, self::PW, true ), array( 'post', array( 'wp_block' ), self::TW, 'ab_mcp_sync_writer_whole' ) ), array( array( 'post', 'wp_block', self::PW ) ) ),
			'no class, whole'                         => array( array( array( 'post', $w, 'AB_MCP_Sync_No_Such_Writer', 'ab_mcp_sync_writer_class' ) ), array( array( 'post', null, null ) ) ),
			'no class, a subtype'                     => array( array( array( 'post', array( 'page' ), 'AB_MCP_Sync_No_Such_Writer', 'ab_mcp_sync_writer_class' ), array( 'post', array( 'page' ), null, 'ab_mcp_sync_writer_class' ) ), array( array( 'post', 'page', null ) ) ),
			'an entry with a stage (Kinds :159)'      => array( array( array( 'menu', array( 'nav_menu_options' ), self::MW, 'ab_mcp_sync_writer_locked' ) ), array( array( 'menu', 'nav_menu_options', null ) ) ),
			'an entry with a stage beside a free one' => array( array( array( 'menu', array( 'nav_menu', 'nav_menu_options' ), self::MW, 'ab_mcp_sync_writer_locked' ) ), array( array( 'menu', 'nav_menu', null ) ) ),
			'a kind that is no name'                  => array( array( array( 'Post', $w, self::PW, 'ab_mcp_sync_writer_invalid' ), array( '', $w, self::PW, 'ab_mcp_sync_writer_invalid' ), array( 'menu-item', $w, self::PW, 'ab_mcp_sync_writer_invalid' ), array( '1post', $w, self::PW, 'ab_mcp_sync_writer_invalid' ), array( str_repeat( 'k', 33 ), $w, self::PW, 'ab_mcp_sync_writer_invalid' ), array( 7, $w, self::PW, 'ab_mcp_sync_writer_invalid' ) ), array( array( 'Post', null, null ) ) ),
			'subtypes that are none'                  => array( array( array( 'post', array(), self::PW, 'ab_mcp_sync_writer_invalid' ), array( 'post', 'page', self::PW, 'ab_mcp_sync_writer_invalid' ), array( 'post', array( '' ), self::PW, 'ab_mcp_sync_writer_invalid' ), array( 'post', array( 'page', 'Page' ), self::PW, 'ab_mcp_sync_writer_invalid' ), array( 'post', array( 'a b' ), self::PW, 'ab_mcp_sync_writer_invalid' ), array( 'post', array( 1 ), self::PW, 'ab_mcp_sync_writer_invalid' ), array( 'post', array( '*' ), self::PW, 'ab_mcp_sync_writer_invalid' ), array( 'post', array( str_repeat( 's', 192 ) ), self::PW, 'ab_mcp_sync_writer_invalid' ), array( 'post', null, self::PW, 'ab_mcp_sync_writer_invalid' ) ), array( array( 'post', 'page', null ), array( 'post', null, null ) ) ),
			'the longest subtype'                     => array( array( array( 'option', array( str_repeat( 's', 191 ), 'my-option_2' ), self::PW, true ) ), array( array( 'option', str_repeat( 's', 191 ), self::PW ), array( 'option', 'my-option_2', self::PW ) ) ),
		);
	}

	#[DataProvider( 'cases' )]
	public function testARegistrationIsTakenOrRefusedWithItsCodeAndNothingRegistered( array $registrations, array $answers ): void {
		foreach ( $registrations as $n => list( $kind, $subtypes, $class, $expect ) ) {
			$before = self::table();
			$got    = AB_MCP_Sync_Writers::register( $kind, $subtypes, $class );
			if ( true === $expect ) {
				self::assertTrue( $got, 'registration ' . $n );
				continue;
			}
			self::assertInstanceOf( WP_Error::class, $got, 'registration ' . $n );
			self::assertSame( $expect, $got->get_error_code(), 'registration ' . $n );
			self::assertSame( $before, self::table(), 'registration ' . $n . ': nothing registered' );
		}
		foreach ( $answers as list( $kind, $subtype, $class ) ) {
			$got = null === $subtype ? AB_MCP_Sync_Writers::whole( $kind ) : AB_MCP_Sync_Writers::of( $kind, $subtype );
			self::assertSame( $class, $got, $kind . ' ' . var_export( $subtype, true ) );
		}
	}

	public function testTheRefusalOfAnEntryWithAStageNamesItsKeyAndStage(): void {
		$got = AB_MCP_Sync_Writers::register( 'menu', array( 'nav_menu_options' ), self::MW );
		self::assertInstanceOf( WP_Error::class, $got );
		self::assertSame( array( 'kind' => 'menu', 'key' => 'option:nav_menu_options', 'stage' => 'Z8d' ), $got->get_error_data() );
		self::assertStringStartsWith( 'option:nav_menu_options is locked until Z8d: ', $got->get_error_message() );
	}

	public function testResetForgetsEveryRegistration(): void {
		self::assertTrue( AB_MCP_Sync_Writers::register( 'post', AB_MCP_Sync_Writers::WHOLE, self::PW ) );
		self::assertTrue( AB_MCP_Sync_Writers::register( 'term', array( 'category' ), self::TW ) );
		AB_MCP_Sync_Writers::reset();
		self::assertSame( array(), self::table() );
		self::assertNull( AB_MCP_Sync_Writers::of( 'post', 'page' ) );
		self::assertNull( AB_MCP_Sync_Writers::of( 'term', 'category' ) );
		self::assertTrue( AB_MCP_Sync_Writers::register( 'post', AB_MCP_Sync_Writers::WHOLE, self::PW ), 'registered anew' );
	}

	public function testTheWritersOfThisPluginAreTheWritersOfTheEngineForEveryKindAndSubtype(): void {
		$this->restore();
		self::assertSame( self::CLASSES, AB_MCP_Sync_Engine::WRITERS );
		self::assertSame( array_keys( self::CLASSES ), array_keys( self::table() ), 'the eight kinds, no other' );
		foreach ( self::CLASSES as $kind => $class ) {
			self::assertSame( $class, AB_MCP_Sync_Writers::whole( $kind ), $kind );
			foreach ( self::SUBTYPES as $subtype ) {
				self::assertSame( $class, AB_MCP_Sync_Writers::of( $kind, $subtype ), $kind . ' ' . $subtype );
				self::assertSame( AB_MCP_Sync_Writers::whole( $kind ), AB_MCP_Sync_Writers::of( $kind, $subtype ), $kind . ' ' . $subtype );
				self::assertSame( $class, AB_MCP_Sync_Engine::writer( $kind, $subtype ), 'writer() with the subtype: ' . $kind . ' ' . $subtype );
				self::assertSame( AB_MCP_Sync_Writers::of( $kind, $subtype ), AB_MCP_Sync_Engine::writer( $kind, $subtype ), $kind . ' ' . $subtype );
			}
			self::assertSame( $class, AB_MCP_Sync_Writers::whole( $kind ), 'whole(): ' . $kind );
			self::assertSame( AB_MCP_Sync_Engine::part_orders( array( $kind ) )[ $kind ], AB_MCP_Sync_Engine::part_order( AB_MCP_Sync_Writers::whole( $kind ) ), $kind );
		}
		foreach ( array( 'code', 'environment', 'no_such_kind' ) as $kind ) {
			self::assertNull( AB_MCP_Sync_Writers::whole( $kind ), $kind );
			self::assertNull( AB_MCP_Sync_Writers::whole( $kind ), $kind );
			self::assertNull( AB_MCP_Sync_Writers::of( $kind, '' ), $kind );
			foreach ( self::SUBTYPES as $subtype ) {
				self::assertNull( AB_MCP_Sync_Engine::writer( $kind, $subtype ), $kind . ' ' . $subtype );
			}
		}
		self::assertSame( array( 'category', 'post_tag', 'post_format', 'wp_pattern_category', 'wp_theme', 'wp_template_part_area' ), array_values( array_unique( array_merge( ...array_map( static fn( $kind ) => defined( AB_MCP_Sync_Writers::whole( $kind ) . '::TAXONOMIES' ) ? constant( AB_MCP_Sync_Writers::whole( $kind ) . '::TAXONOMIES' ) : array(), array_keys( AB_MCP_Sync_Engine::WRITERS ) ) ) ) ), 'the taxonomies of the registered writers, in the order run_post() forms them (E4)' );
	}

	public function testWriterAsksTheRegistrationBySubtype(): void {
		self::assertTrue( AB_MCP_Sync_Writers::register( 'post', array( 'post', 'page' ), self::PW ) );
		self::assertSame( self::PW, AB_MCP_Sync_Engine::writer( 'post', 'page' ) );
		self::assertSame( self::PW, AB_MCP_Sync_Engine::writer( 'post', 'post' ) );
		self::assertNull( AB_MCP_Sync_Engine::writer( 'post', 'wp_block' ), 'a subtype not registered' );
		self::assertNull( AB_MCP_Sync_Writers::whole( 'post' ), 'no whole kind' );
		self::assertNull( AB_MCP_Sync_Engine::writer( 'post', '' ), 'no whole kind' );
		self::assertNull( AB_MCP_Sync_Engine::writer( 'term', 'category' ), 'a kind not registered' );
	}

	public function testAllIsTheTableOfTheRegistrationByKindAndSubtype(): void {
		$this->restore();
		self::assertSame( array_map( static fn( string $class ): array => array( AB_MCP_Sync_Writers::WHOLE => $class ), self::CLASSES ), AB_MCP_Sync_Writers::all(), 'this plugin: each kind whole with its class, in the order of WRITERS' );
		AB_MCP_Sync_Writers::reset();
		self::assertTrue( AB_MCP_Sync_Writers::register( 'post', array( 'post', 'page' ), self::PW ) );
		self::assertTrue( AB_MCP_Sync_Writers::register( 'term', array( 'category' ), self::TW ) );
		self::assertSame( array( 'post' => array( 'post' => self::PW, 'page' => self::PW ), 'term' => array( 'category' => self::TW ) ), AB_MCP_Sync_Writers::all(), 'by subtype' );
	}

	public function testAFreshProcessThatLoadsTheEngineHasEveryKindWhole(): void {
		$root = dirname( __DIR__ );
		$code = 'require ' . var_export( $root . '/tests/bootstrap.php', true ) . '; require ' . var_export( $root . '/includes/sync/class-sync-engine.php', true ) . ';'
			. ' echo json_encode( ( new ReflectionProperty( "AB_MCP_Sync_Writers", "writers" ) )->getValue() );';
		$lines = array();
		exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $code ) . ' 2>&1', $lines, $status );
		self::assertSame( 0, $status, implode( "\n", $lines ) );
		$whole = array();
		foreach ( self::CLASSES as $kind => $class ) {
			$whole[ $kind ] = array( AB_MCP_Sync_Writers::WHOLE => $class );
		}
		self::assertSame( $whole, json_decode( implode( "\n", $lines ), true ), 'the eight kinds, each whole with its class, no other' );
	}
}
