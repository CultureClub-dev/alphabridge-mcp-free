<?php
/**
 * The write side of the object of a journal (AB_MCP_Sync_Engine::writer_of(),
 * the seam, first step T2, I5): a journal without a kind is of the post as
 * before; one of a kind registered whole gives its class as before, with any
 * subtype or none; one of a kind registered by subtype (here only, two
 * subtypes with different classes each) gives the class of its subtype as
 * the core reads it from the identity (post type, else taxonomy, else name),
 * also through writer( $kind, $subtype ), and none for a subtype missing,
 * unknown or empty.
 *
 * The registrations made as the engine loads are kept before each test and
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
use ReflectionMethod;
use ReflectionProperty;

require_once dirname( __DIR__ ) . '/includes/sync/class-sync-engine.php';

final class SyncWriterOfTest extends TestCase {

	private const PW = 'AB_MCP_Sync_Post_Writer';
	private const TW = 'AB_MCP_Sync_Term_Writer';
	private const MW = 'AB_MCP_Sync_Menu_Writer';

	/** @var array|null The table as the engine registered it. */
	private $kept = null;

	protected function setUp(): void {
		$this->kept = ( new ReflectionProperty( AB_MCP_Sync_Writers::class, 'writers' ) )->getValue();
	}

	protected function tearDown(): void {
		( new ReflectionProperty( AB_MCP_Sync_Writers::class, 'writers' ) )->setValue( null, $this->kept );
	}

	private static function writer_of( array $journal ) {
		return ( new ReflectionMethod( AB_MCP_Sync_Engine::class, 'writer_of' ) )->invoke( null, $journal );
	}

	private static function subtype_in( array $journal ) {
		return ( new ReflectionMethod( AB_MCP_Sync_Engine::class, 'subtype_in' ) )->invoke( null, $journal );
	}

	/** Only here: post, term and option registered by subtype, two or three classes each. */
	private static function split(): void {
		AB_MCP_Sync_Writers::reset();
		$registrations = array(
			array( 'post', array( 'page' ), self::PW ),
			array( 'post', array( 'wp_block' ), self::TW ),
			array( 'post', array( 'category' ), self::MW ),
			array( 'term', array( 'post_tag' ), self::PW ),
			array( 'term', array( 'category' ), self::TW ),
			array( 'term', array( 'blogname' ), self::MW ),
			array( 'option', array( 'blogname' ), self::PW ),
			array( 'option', array( 'blogdescription' ), self::TW ),
		);
		foreach ( $registrations as list( $kind, $subtypes, $class ) ) {
			self::assertTrue( AB_MCP_Sync_Writers::register( $kind, $subtypes, $class ), $kind . ' ' . $subtypes[0] );
		}
	}

	public function testAJournalWithoutAKindIsOfThePostAsBefore(): void {
		self::assertSame( self::PW, AB_MCP_Sync_Engine::WRITERS['post'] );
		self::assertSame( self::PW, self::writer_of( array( 'object' => array( 'op' => 'update', 'live_ref' => '5' ) ) ) );
		self::assertSame( self::PW, self::writer_of( array( 'object' => array( 'identity' => array( 'post_type' => 'page' ) ) ) ) );
		self::assertSame( self::PW, self::writer_of( array() ), 'a journal without its object' );
	}

	public function testAJournalOfAKindRegisteredWholeGivesItsClassAsBefore(): void {
		foreach ( AB_MCP_Sync_Engine::WRITERS as $kind => $class ) {
			self::assertSame( $class, self::writer_of( array( 'object' => array( 'kind' => $kind ) ) ), $kind . ' without a subtype' );
			foreach ( array( array( 'post_type' => 'wp_block' ), array( 'taxonomy' => 'category' ), array( 'name' => 'blogname' ), array( 'post_type' => '' ) ) as $identity ) {
				self::assertSame( $class, self::writer_of( array( 'object' => array( 'kind' => $kind, 'identity' => $identity ) ) ), $kind . ' ' . key( $identity ) );
			}
		}
		self::assertNull( self::writer_of( array( 'object' => array( 'kind' => 'code' ) ) ), 'a kind no step writes' );
	}

	/**
	 * Case => the object of a journal, the subtype the core reads, the class
	 * of writer_of() and of writer( $kind, $subtype ) (null: none).
	 */
	public static function cases(): array {
		return array(
			'post_type'                    => array( array( 'kind' => 'post', 'identity' => array( 'post_type' => 'wp_block', 'post_name' => 'x' ) ), 'wp_block', self::TW ),
			'post_type, the other'         => array( array( 'kind' => 'post', 'identity' => array( 'post_type' => 'page' ) ), 'page', self::PW ),
			'taxonomy'                     => array( array( 'kind' => 'term', 'identity' => array( 'taxonomy' => 'post_tag', 'slug' => 'x' ) ), 'post_tag', self::PW ),
			'taxonomy, the other'          => array( array( 'kind' => 'term', 'identity' => array( 'taxonomy' => 'category' ) ), 'category', self::TW ),
			'name'                         => array( array( 'kind' => 'option', 'identity' => array( 'name' => 'blogdescription' ) ), 'blogdescription', self::TW ),
			'name, the other'              => array( array( 'kind' => 'option', 'identity' => array( 'name' => 'blogname' ) ), 'blogname', self::PW ),
			'post_type before taxonomy'    => array( array( 'kind' => 'post', 'identity' => array( 'taxonomy' => 'category', 'post_type' => 'page' ) ), 'page', self::PW ),
			'taxonomy before name'         => array( array( 'kind' => 'term', 'identity' => array( 'name' => 'blogname', 'taxonomy' => 'category' ) ), 'category', self::TW ),
			'no kind: the post, by type'   => array( array( 'identity' => array( 'post_type' => 'wp_block' ) ), 'wp_block', self::TW ),
			'no subtype'                   => array( array( 'kind' => 'post', 'identity' => array( 'post_name' => 'x', 'slug' => 'x' ) ), '', null ),
			'no identity'                  => array( array( 'kind' => 'term' ), '', null ),
			'no kind and no subtype'       => array( array( 'op' => 'update' ), '', null ),
			'an unknown subtype'           => array( array( 'kind' => 'post', 'identity' => array( 'post_type' => 'wp_template' ) ), 'wp_template', null ),
			'an empty subtype'             => array( array( 'kind' => 'post', 'identity' => array( 'post_type' => '', 'taxonomy' => 'category' ) ), '', null ),
		);
	}

	#[DataProvider( 'cases' )]
	public function testAJournalOfAKindRegisteredBySubtypeGivesTheClassOfItsSubtype( array $object, string $subtype, ?string $class ): void {
		self::split();
		$journal = array( 'object' => $object );
		self::assertSame( $subtype, self::subtype_in( $journal ), 'the subtype the core reads' );
		self::assertSame( $class, self::writer_of( $journal ), 'writer_of()' );
		self::assertSame( $class, AB_MCP_Sync_Engine::writer( $object['kind'] ?? 'post', $subtype ), 'writer( $kind, $subtype )' );
		self::assertNull( AB_MCP_Sync_Engine::writer( $object['kind'] ?? 'post' ), 'no whole kind' );
	}
}
