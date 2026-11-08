<?php
/**
 * The index of identities of a site (AB_MCP_Sync_Identity; Z8 branch 6,
 * Codex round 4) against a $wpdb that answers its readers page by page from
 * a site a test describes:
 *
 * - compact: 20 000 posts and 50 000 attachments, each with a language of
 *   WPML, take less than 32 MiB to build and some 3 MiB once built;
 * - in parts: an index built a page a call, kept and taken up between the
 *   calls (stored(), reset()), is the index built at once; one of another
 *   context or format is none;
 * - one identity per object of the site: an identity another object has as
 *   well names none and both are twins (posts under one parent, templates of
 *   one theme, attachments of one file, terms of one taxonomy and slug, menu
 *   items of one target under one parent), each saying how many objects have
 *   it, also in an index taken up; under a parent without one, none;
 *   the language of Polylang, else of WPML;
 * - a table that cannot be read is named, and every identity is none.
 *
 * What WordPress writes and reads is shown against two sites in
 * tests/integration (SyncProposalTest).
 *
 * @package AlphaBridge_MCP
 */

namespace AlphaBridge\Tests;

use AB_MCP_Sync_Canon;
use AB_MCP_Sync_Export;
use AB_MCP_Sync_Identity;
use AB_MCP_Sync_Inventory;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/sync/class-sync-identity.php';
require_once __DIR__ . '/../includes/sync/class-sync-inventory.php';
require_once __DIR__ . '/../includes/sync/class-sync-export.php';
require_once __DIR__ . '/SyncIdentitySites.php';

final class SyncIdentityTest extends TestCase {

	/** @var mixed */
	private $saved;

	protected function setUp(): void {
		$this->saved = $GLOBALS['wpdb'] ?? null;
		ab_test_reset();
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->saved;
		AB_MCP_Sync_Identity::reset();
		AB_MCP_Sync_Inventory::$clock = null;
	}

	private static function ctx(): array {
		return AB_MCP_Sync_Canon::context( array( 'https://live.example', 'https://staging.example' ) );
	}

	/** The site as this site's $wpdb, the index forgotten. */
	private static function serve( callable $site ): SyncIdentityFakeWpdb {
		$GLOBALS['wpdb'] = new SyncIdentityFakeWpdb( $site );
		AB_MCP_Sync_Identity::reset( self::ctx() );
		return $GLOBALS['wpdb'];
	}

	/** The identity a post of these parts has under a parent (hex; '' at the top). */
	private static function of_post( string $type, string $theme, string $slug, string $lang, string $parent = '' ): string {
		return bin2hex( AB_MCP_Sync_Identity::under( AB_MCP_Sync_Identity::part( array( 'post', $type, $theme, $slug, $lang ) ), '' === $parent ? '' : (string) hex2bin( $parent ) ) );
	}

	/* ------------------------------------------------------ compact */

	/**
	 * 20 000 pages (a level of children under every tenth) and 50 000
	 * attachments, each with a language of WPML: the build stays under
	 * 32 MiB (Codex round 4, B: some 177 MiB before), the index once built
	 * holds some 3 MiB, and a stored one is taken up the same.
	 *
	 * In a process of its own: what it measures is the build, not what the
	 * tests before it left in the memory of the suite. Before them some
	 * 120 MiB were held there (74 MiB used, the rest gaps between them),
	 * and the build's large blocks found no room under a memory_limit of
	 * 128M (branch 11 of Z8; the same at bb85883 within 1 MiB).
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function testTheIndexOfALargeSiteStaysCompact(): void {
		$pages = 20000;
		$files = 50000;
		$site  = static function ( string $what, array $a ) use ( $pages, $files ): array {
			$rows = array();
			switch ( $what ) {
				case 'posts':
					// Every third id: the ids of a site are not dense.
					for ( $k = intdiv( (int) $a[0], 3 ) + 1; $k <= $pages + $files && count( $rows ) < (int) $a[1]; $k++ ) {
						$rows[] = $k <= $pages
							? array( 'ID' => 3 * $k, 'post_type' => 'page', 'post_name' => 'seite-' . $k, 'post_parent' => 0 === $k % 10 ? 0 : 3 * ( $k - $k % 10 ) )
							: array( 'ID' => 3 * $k, 'post_type' => 'attachment', 'post_name' => 'bild-' . $k, 'post_parent' => 3 * ( 1 + $k % $pages ) );
					}
					return $rows;
				case 'meta':
					for ( $m = (int) $a[1] + 1; $m <= $files && count( $rows ) < (int) $a[2]; $m++ ) {
						$rows[] = array( 'meta_id' => $m, 'post_id' => 3 * ( $pages + $m ), 'meta_value' => '2026/11/bild-' . $m . '.jpg' );
					}
					return $rows;
				case 'wpml_table':
					return array( array( 'TABLE_NAME' => 'wp_icl_translations' ) );
				case 'wpml':
					for ( $t = (int) $a[0] + 1; $t <= $pages + $files && count( $rows ) < (int) $a[1]; $t++ ) {
						$rows[] = array( 'translation_id' => $t, 'element_type' => $t <= $pages ? 'post_page' : 'post_attachment', 'element_id' => 3 * $t, 'language_code' => 0 === $t % 2 ? 'de' : 'en' );
					}
					return $rows;
				case 'titles':
				case 'item_meta':
				case 'relations':
				case 'terms':
				case 'type_of':
					return array();
			}
			return array();
		};
		self::serve( $site );
		gc_collect_cycles();
		memory_reset_peak_usage();
		$before = memory_get_usage();
		self::assertTrue( AB_MCP_Sync_Identity::build() );
		$built = memory_get_peak_usage() - $before;
		$held  = memory_get_usage() - $before;
		self::assertLessThan( 32 * 1024 * 1024, $built, sprintf( 'The build took %.1f MiB.', $built / 1048576 ) );
		self::assertLessThan( 4 * 1024 * 1024, $held, sprintf( 'The index holds %.1f MiB.', $held / 1048576 ) );
		$ctx = self::ctx();
		self::assertSame( self::of_post( 'page', '', 'seite-10', 'de' ), AB_MCP_Sync_Identity::post( 30, $ctx ) );
		self::assertSame( self::of_post( 'page', '', 'seite-13', 'en', self::of_post( 'page', '', 'seite-10', 'de' ) ), AB_MCP_Sync_Identity::post( 39, $ctx ), 'A child with the identity of its parent.' );
		self::assertSame( bin2hex( AB_MCP_Sync_Identity::part( array( 'attachment', '2026/11/bild-7.jpg', 'en' ) ) ), AB_MCP_Sync_Identity::post( 3 * ( $pages + 7 ), $ctx ) );
		self::assertSame( 'de', AB_MCP_Sync_Identity::language( 'post', 3 * ( $pages + 8 ), $ctx ) );
		// Kept and taken up in the next call of the run: the same, and as small.
		$text = AB_MCP_Sync_Identity::stored();
		self::assertIsString( $text );
		self::assertLessThan( 4 * 1024 * 1024, strlen( $text ) );
		AB_MCP_Sync_Identity::reset( $ctx, $text );
		unset( $text );
		$asked = $GLOBALS['wpdb']->asked;
		self::assertTrue( AB_MCP_Sync_Identity::build() );
		self::assertSame( $asked, $GLOBALS['wpdb']->asked, 'A stored index asks the database nothing.' );
		self::assertSame( self::of_post( 'page', '', 'seite-13', 'en', self::of_post( 'page', '', 'seite-10', 'de' ) ), AB_MCP_Sync_Identity::post( 39, $ctx ) );
		self::assertNull( AB_MCP_Sync_Identity::stored(), 'Nothing new to keep.' );
	}

	/* ------------------------------------------------------- in parts */

	/** A site of every kind the index reads, its languages of Polylang and WPML. */
	private static function full_site(): SyncIdentitySite {
		$s = new SyncIdentitySite();
		$s->post( 1, 'page', 'team' );
		$s->post( 2, 'page', 'kontakt', 1 );
		$s->post( 3, 'page', 'kontakt' );
		$s->post( 4, 'page', 'kontakt' );
		$s->post( 5, 'page', 'unter', 3 );
		$s->post( 6, 'wp_template', 'index' );
		$s->relate( 'wp_theme', 6, 800, 'theme-a' );
		$s->post( 7, 'attachment', 'bild' );
		$s->meta( 7, '_wp_attached_file', '2026/11/bild.jpg' );
		$s->post( 8, 'page', 'translated' );
		$s->relate( 'language', 8, 700, 'de' );
		$s->post( 9, 'post', 'meldung' );
		$s->term( 30, 300, 'category', 'news' );
		$s->term( 31, 301, 'category', 'tips' );
		$s->relate( 'term_language', 31, 701, 'fr' );
		$s->wpml = array( array( 'translation_id' => 1, 'element_type' => 'post_post', 'element_id' => 9, 'language_code' => 'it' ), array( 'translation_id' => 2, 'element_type' => 'tax_category', 'element_id' => 300, 'language_code' => 'es' ) );
		$s->item( 40, 'Team', array( '_menu_item_type' => 'post_type', '_menu_item_object' => 'page', '_menu_item_object_id' => 1, '_menu_item_menu_item_parent' => 0 ) );
		$s->item( 41, 'News', array( '_menu_item_type' => 'taxonomy', '_menu_item_object' => 'category', '_menu_item_object_id' => 30, '_menu_item_menu_item_parent' => 40 ) );
		$s->item( 42, 'Privat', array( '_menu_item_type' => 'custom', '_menu_item_url' => 'https://live.example/#privat', '_menu_item_menu_item_parent' => 0 ) );
		return $s;
	}

	/** What the index says of every object of full_site(). */
	private static function all_of( array $ctx ): array {
		$out = array();
		foreach ( range( 1, 9 ) as $id ) {
			$out[ 'post ' . $id ] = array( AB_MCP_Sync_Identity::post( $id, $ctx ), AB_MCP_Sync_Identity::language( 'post', $id, $ctx ), AB_MCP_Sync_Identity::twin( array( 'kind' => 'post', 'ref' => (string) $id ), $ctx ) );
		}
		foreach ( array( 30 => 300, 31 => 301 ) as $id => $ttid ) {
			$out[ 'term ' . $id ] = array( AB_MCP_Sync_Identity::term( $id, $ctx ), AB_MCP_Sync_Identity::language( 'term', $id, $ctx, $ttid ) );
		}
		foreach ( array( 40, 41, 42 ) as $id ) {
			$out[ 'item ' . $id ] = array( AB_MCP_Sync_Identity::post( $id, $ctx ), AB_MCP_Sync_Identity::menu_item( $id, $ctx ) );
		}
		return $out;
	}

	public function testAnIndexBuiltInPartsIsTheIndexBuiltAtOnce(): void {
		$site = self::full_site();
		$ctx  = self::ctx();
		self::serve( $site );
		self::assertTrue( AB_MCP_Sync_Identity::build() );
		$once = self::all_of( $ctx );
		$text = AB_MCP_Sync_Identity::stored();
		// A page a call: out of time before each page but the first.
		self::serve( $site );
		$calls  = 0;
		$stored = null;
		do {
			AB_MCP_Sync_Identity::reset( $ctx, $stored );
			$done   = AB_MCP_Sync_Identity::build( static fn(): bool => true );
			$stored = AB_MCP_Sync_Identity::stored();
			self::assertIsString( $stored, 'Each call keeps what it built.' );
			++$calls;
		} while ( true !== $done && $calls < 50 );
		self::assertTrue( $done );
		self::assertGreaterThan( 5, $calls, 'Built over several calls.' );
		self::assertSame( $text, $stored, 'The same index.' );
		AB_MCP_Sync_Identity::reset( $ctx, $stored );
		self::assertSame( $once, self::all_of( $ctx ) );
		self::assertNotNull( $once['post 2'][0] );
		self::assertSame( 'de', $once['post 8'][1], 'Polylang.' );
		self::assertSame( 'it', $once['post 9'][1], 'WPML.' );
		self::assertSame( 'es', $once['term 30'][1], 'WPML by the term_taxonomy_id.' );
		self::assertSame( 'fr', $once['term 31'][1], 'Polylang by the term id.' );
		self::assertNotNull( $once['item 41'][1] );
	}

	public function testAStoredIndexOfAnotherContextOrFormatIsNone(): void {
		$site = self::full_site();
		self::serve( $site );
		AB_MCP_Sync_Identity::build();
		$text = (string) AB_MCP_Sync_Identity::stored();
		$db   = self::serve( $site );
		foreach ( array(
			'another context' => array( AB_MCP_Sync_Canon::context( array( 'https://other.example' ) ), $text ),
			'another format'  => array( self::ctx(), str_replace( AB_MCP_Sync_Identity::FORMAT, 'ab-sync-identity/0', $text ) ),
			'cut'             => array( self::ctx(), substr( $text, 0, -1 ) ),
			'no head'         => array( self::ctx(), 'x' ),
		) as $what => list( $ctx, $stored ) ) {
			AB_MCP_Sync_Identity::reset( $ctx, $stored );
			$asked = $db->asked;
			self::assertTrue( AB_MCP_Sync_Identity::build() );
			self::assertGreaterThan( $asked, $db->asked, $what . ': built anew.' );
		}
	}

	/* ---------------------------------------- one identity per object */

	public function testAnIdentityAnotherObjectHasNamesNoneAndBothAreTwins(): void {
		$s = new SyncIdentitySite();
		$s->post( 1, 'page', 'kontakt' );
		$s->post( 2, 'page', 'kontakt' );
		$s->post( 3, 'page', 'unter', 1 );
		$s->post( 4, 'page', 'team' );
		$s->post( 5, 'page', 'eltern-a' );
		$s->post( 6, 'page', 'info', 5 );
		$s->post( 7, 'page', 'eltern-b' );
		$s->post( 8, 'page', 'info', 7 );
		$s->post( 9, 'page', '' );
		$s->post( 10, 'page', 'unter-entwurf', 9 );
		$s->post( 11, 'page', 'waise', 999 );
		foreach ( array( 20 => 'theme-a', 21 => 'theme-a', 22 => 'theme-b' ) as $id => $theme ) {
			$s->post( $id, 'wp_template', 'index' );
			$s->relate( 'wp_theme', $id, 800 + $id, $theme );
		}
		$s->post( 23, 'wp_template', 'single' );
		$s->relate( 'wp_theme', 23, 823, 'theme-a' );
		$s->relate( 'wp_theme', 23, 824, 'theme-b' );
		foreach ( array( 30 => 'same.jpg', 31 => 'same.jpg', 32 => 'other.jpg' ) as $id => $file ) {
			$s->post( $id, 'attachment', 'f' . $id );
			$s->meta( $id, '_wp_attached_file', '2026/11/' . $file );
		}
		$s->post( 33, 'attachment', 'two-files' );
		$s->meta( 33, '_wp_attached_file', '2026/11/a.jpg' );
		$s->meta( 33, '_wp_attached_file', '2026/11/b.jpg' );
		$s->post( 34, 'attachment', 'no-file' );
		$s->post( 35, 'page', 'unter-bild', 32 );
		$s->post( 36, 'attachment', 'empty-file' );
		$s->meta( 36, '_wp_attached_file', '' );
		$s->post( 37, 'page', 'unter-zwillingsbild', 30 );
		$s->item( 38, 'Menü', array( '_menu_item_type' => 'custom', '_menu_item_url' => '#' ) );
		$s->post( 39, 'page', 'unter-menue', 38 );
		$s->post( 12, 'post', 'team' );
		$s->term( 50, 500, 'category', 'news' );
		$s->term( 51, 501, 'category', 'news' );
		$s->term( 52, 502, 'post_tag', 'news' );
		$s->term( 53, 503, 'category', '' );
		$s->term( 54, 504, 'category', 'shared' );
		$s->term( 54, 505, 'post_tag', 'shared' );
		self::serve( $s );
		$ctx  = self::ctx();
		$twin = static fn( string $kind, int $ref, array $identity = array() ): bool => AB_MCP_Sync_Identity::twin( array( 'kind' => $kind, 'ref' => (string) $ref, 'identity' => $identity ), $ctx );
		self::assertNull( AB_MCP_Sync_Identity::post( 1, $ctx ), 'A slug twice under one parent.' );
		self::assertNull( AB_MCP_Sync_Identity::post( 2, $ctx ) );
		self::assertTrue( $twin( 'post', 1 ) && $twin( 'post', 2 ), 'Both twins.' );
		self::assertNull( AB_MCP_Sync_Identity::post( 3, $ctx ), 'Under a twin: none.' );
		self::assertFalse( $twin( 'post', 3 ), '… and no twin itself.' );
		self::assertSame( self::of_post( 'page', '', 'team', '' ), AB_MCP_Sync_Identity::post( 4, $ctx ) );
		self::assertFalse( $twin( 'post', 4 ) );
		self::assertNotNull( AB_MCP_Sync_Identity::post( 6, $ctx ), 'One slug under two parents: two identities.' );
		self::assertNotSame( AB_MCP_Sync_Identity::post( 6, $ctx ), AB_MCP_Sync_Identity::post( 8, $ctx ) );
		self::assertNull( AB_MCP_Sync_Identity::post( 9, $ctx ), 'Without a slug.' );
		self::assertFalse( $twin( 'post', 9 ) );
		self::assertNull( AB_MCP_Sync_Identity::post( 10, $ctx ), 'Under a parent without a slug.' );
		self::assertNull( AB_MCP_Sync_Identity::post( 11, $ctx ), 'Under a parent that is not there.' );
		self::assertNull( AB_MCP_Sync_Identity::post( 20, $ctx ), 'A template of one theme twice.' );
		self::assertTrue( $twin( 'post', 21 ) );
		self::assertSame( self::of_post( 'wp_template', 'theme-b', 'index', '' ), AB_MCP_Sync_Identity::post( 22, $ctx ), 'The template of another theme.' );
		self::assertNull( AB_MCP_Sync_Identity::post( 23, $ctx ), 'A template of two themes.' );
		self::assertNull( AB_MCP_Sync_Identity::post( 30, $ctx ), 'One file twice.' );
		self::assertTrue( $twin( 'post', 31 ) );
		self::assertSame( bin2hex( AB_MCP_Sync_Identity::part( array( 'attachment', '2026/11/other.jpg', '' ) ) ), AB_MCP_Sync_Identity::post( 32, $ctx ) );
		self::assertNull( AB_MCP_Sync_Identity::post( 33, $ctx ), 'An attachment of two files.' );
		self::assertNull( AB_MCP_Sync_Identity::post( 34, $ctx ), 'An attachment without a file.' );
		self::assertNull( AB_MCP_Sync_Identity::post( 36, $ctx ), 'An attachment of an empty file.' );
		self::assertNull( AB_MCP_Sync_Identity::post( 37, $ctx ), 'Under an attachment of a file twice.' );
		self::assertNull( AB_MCP_Sync_Identity::post( 39, $ctx ), 'Under a menu item.' );
		self::assertSame( self::of_post( 'post', '', 'team', '' ), AB_MCP_Sync_Identity::post( 12, $ctx ), 'A post of another type with the slug of a page: another identity.' );
		self::assertSame( self::of_post( 'page', '', 'unter-bild', '', (string) AB_MCP_Sync_Identity::post( 32, $ctx ) ), AB_MCP_Sync_Identity::post( 35, $ctx ), 'Under an attachment: its identity.' );
		self::assertNull( AB_MCP_Sync_Identity::term( 50, $ctx, 'category' ), 'A slug twice in a taxonomy.' );
		self::assertTrue( $twin( 'term', 51, array( 'taxonomy' => 'category' ) ) );
		self::assertSame( bin2hex( AB_MCP_Sync_Identity::part( array( 'term', 'post_tag', 'news', '' ) ) ), AB_MCP_Sync_Identity::term( 52, $ctx ), 'The same slug in another taxonomy.' );
		self::assertFalse( $twin( 'term', 52, array( 'taxonomy' => 'post_tag' ) ) );
		self::assertNull( AB_MCP_Sync_Identity::term( 53, $ctx ), 'A term without a slug.' );
		self::assertNull( AB_MCP_Sync_Identity::term( 54, $ctx ), 'A term id in two taxonomies names none by the id alone.' );
		self::assertNotNull( AB_MCP_Sync_Identity::term( 54, $ctx, 'post_tag' ) );
		self::assertNull( AB_MCP_Sync_Identity::post( 999, $ctx ), 'Not there.' );
	}

	public function testATwinSaysHowManyObjectsOfItsSiteHaveItsIdentity(): void {
		$s = new SyncIdentitySite();
		foreach ( array( 1, 2, 3 ) as $id ) {
			$s->post( $id, 'page', 'drei' );
		}
		$s->post( 4, 'page', 'zwei' );
		$s->post( 5, 'page', 'zwei' );
		$s->post( 6, 'page', 'allein' );
		$s->post( 7, 'page', 'drei', 6 );
		foreach ( array( 10, 11, 12 ) as $id ) {
			$s->post( $id, 'attachment', 'f' . $id );
			$s->meta( $id, '_wp_attached_file', '2026/11/same.jpg' );
		}
		$s->term( 20, 200, 'category', 'news' );
		$s->term( 21, 201, 'category', 'news' );
		$s->term( 23, 203, 'category', 'news' );
		$s->term( 22, 202, 'post_tag', 'news' );
		$s->term( 24, 204, 'post_tag', 'tipps' );
		$s->term( 25, 205, 'post_tag', 'tipps' );
		$s->item( 30, 'Firma', array( '_menu_item_type' => 'custom', '_menu_item_url' => '#', '_menu_item_menu_item_parent' => 0 ) );
		$s->item( 31, 'Firma', array( '_menu_item_type' => 'custom', '_menu_item_url' => '#', '_menu_item_menu_item_parent' => 0 ) );
		$ctx    = self::ctx();
		$counts = static function () use ( $ctx ): array {
			$out = array();
			foreach ( array( 1, 2, 3, 4, 5, 6, 7, 10, 11, 12, 30, 31 ) as $id ) {
				$out[ 'post ' . $id ] = AB_MCP_Sync_Identity::twins( array( 'kind' => $id >= 30 ? 'menu_item' : 'post', 'ref' => (string) $id ), $ctx );
			}
			foreach ( array( 20 => 'category', 21 => 'category', 23 => 'category', 22 => 'post_tag', 24 => 'post_tag', 25 => 'post_tag' ) as $id => $taxonomy ) {
				$out[ 'term ' . $id ] = AB_MCP_Sync_Identity::twins( array( 'kind' => 'term', 'ref' => (string) $id, 'identity' => array( 'taxonomy' => $taxonomy ) ), $ctx );
			}
			return $out;
		};
		self::serve( $s );
		$expected = array(
			'post 1'  => 3,
			'post 2'  => 3,
			'post 3'  => 3,
			'post 4'  => 2,
			'post 5'  => 2,
			'post 6'  => 0,
			'post 7'  => 0,
			'post 10' => 3,
			'post 11' => 3,
			'post 12' => 3,
			'post 30' => 2,
			'post 31' => 2,
			'term 20' => 3,
			'term 21' => 3,
			'term 23' => 3,
			'term 22' => 0,
			'term 24' => 2,
			'term 25' => 2,
		);
		self::assertSame( $expected, $counts(), 'Three posts of one slug, two of another, one alone and one under another parent; three attachments of one file; three terms of one slug, one of it in another taxonomy and two of another slug there; two links of one address and label.' );
		self::assertFalse( AB_MCP_Sync_Identity::twin( array( 'kind' => 'post', 'ref' => '6' ), $ctx ) );
		self::assertTrue( AB_MCP_Sync_Identity::twin( array( 'kind' => 'post', 'ref' => '1' ), $ctx ) );
		self::assertSame( 0, AB_MCP_Sync_Identity::twins( array( 'kind' => 'option', 'ref' => 'blogname' ), $ctx ), 'Another kind is never a twin.' );
		// A page a call: the counts are the same in the index taken up.
		self::serve( $s );
		$stored = null;
		$calls  = 0;
		do {
			AB_MCP_Sync_Identity::reset( $ctx, $stored );
			$done   = AB_MCP_Sync_Identity::build( static fn(): bool => true );
			$stored = AB_MCP_Sync_Identity::stored();
		} while ( true !== $done && ++$calls < 50 );
		AB_MCP_Sync_Identity::reset( $ctx, $stored );
		self::assertSame( $expected, $counts(), 'Built in parts.' );
	}

	public function testAPostUnderAParentThatIsNotThereHasNone(): void {
		$s = new SyncIdentitySite();
		$s->post( 1, 'attachment', 'erstes-bild' );
		$s->meta( 1, '_wp_attached_file', '2026/11/erstes.jpg' );
		$s->post( 2, 'page', 'waise', 999 );
		$s->post( 3, 'page', 'unter-bild', 1 );
		self::serve( $s );
		$ctx = self::ctx();
		self::assertNull( AB_MCP_Sync_Identity::post( 2, $ctx ), 'No parent there: none, whatever post the index holds first.' );
		self::assertSame( self::of_post( 'page', '', 'unter-bild', '', (string) AB_MCP_Sync_Identity::post( 1, $ctx ) ), AB_MCP_Sync_Identity::post( 3, $ctx ) );
	}

	public function testTheLanguageOfPolylangThenWpml(): void {
		$s = new SyncIdentitySite();
		$s->post( 1, 'page', 'kontakt' );
		$s->relate( 'language', 1, 700, 'de' );
		$s->post( 2, 'page', 'kontakt' );
		$s->relate( 'language', 2, 701, 'en' );
		$s->post( 3, 'page', 'beides' );
		$s->relate( 'language', 3, 700, 'de' );
		$s->relate( 'language', 3, 701, 'en' );
		$s->post( 4, 'page', 'wpml' );
		$s->post( 5, 'page', 'wpml-twice' );
		$s->post( 6, 'page', 'wpml-two' );
		$s->post( 7, 'page', 'both' );
		$s->relate( 'language', 7, 702, 'fr' );
		$s->post( 8, 'attachment', 'bild-de' );
		$s->meta( 8, '_wp_attached_file', '2026/11/bild.jpg' );
		$s->relate( 'language', 8, 700, 'de' );
		$s->post( 9, 'attachment', 'bild-en' );
		$s->meta( 9, '_wp_attached_file', '2026/11/bild.jpg' );
		$s->relate( 'language', 9, 701, 'en' );
		$s->term( 30, 300, 'category', 'news' );
		$s->relate( 'term_language', 30, 703, 'de' );
		$s->term( 31, 301, 'category', 'news' );
		$s->relate( 'term_language', 31, 704, 'en' );
		$s->term( 32, 302, 'category', 'wpml' );
		$s->term( 33, 303, 'category', 'beides' );
		$s->relate( 'term_language', 33, 703, 'de' );
		$s->relate( 'term_language', 33, 704, 'en' );
		$s->term( 34, 304, 'category', 'shared' );
		$s->term( 34, 305, 'post_tag', 'shared' );
		$s->post( 10, 'attachment', 'bild-beides' );
		$s->meta( 10, '_wp_attached_file', '2026/11/beides.jpg' );
		$s->relate( 'language', 10, 700, 'de' );
		$s->relate( 'language', 10, 701, 'en' );
		$s->wpml = array(
			array( 'translation_id' => 1, 'element_type' => 'post_page', 'element_id' => 4, 'language_code' => 'it' ),
			array( 'translation_id' => 2, 'element_type' => 'post_page', 'element_id' => 5, 'language_code' => 'it' ),
			array( 'translation_id' => 3, 'element_type' => 'post_page', 'element_id' => 5, 'language_code' => 'it' ),
			array( 'translation_id' => 4, 'element_type' => 'post_page', 'element_id' => 6, 'language_code' => 'it' ),
			array( 'translation_id' => 5, 'element_type' => 'post_page', 'element_id' => 6, 'language_code' => 'es' ),
			array( 'translation_id' => 6, 'element_type' => 'post_page', 'element_id' => 7, 'language_code' => 'es' ),
			array( 'translation_id' => 7, 'element_type' => 'tax_category', 'element_id' => 302, 'language_code' => 'pt' ),
			array( 'translation_id' => 8, 'element_type' => 'other_type', 'element_id' => 4, 'language_code' => 'xx' ),
			array( 'translation_id' => 9, 'element_type' => 'tax_post_tag', 'element_id' => 305, 'language_code' => 'nl' ),
		);
		self::serve( $s );
		$ctx = self::ctx();
		self::assertSame( self::of_post( 'page', '', 'kontakt', 'de' ), AB_MCP_Sync_Identity::post( 1, $ctx ), 'Translations sharing a slug: one identity each.' );
		self::assertSame( self::of_post( 'page', '', 'kontakt', 'en' ), AB_MCP_Sync_Identity::post( 2, $ctx ) );
		self::assertNull( AB_MCP_Sync_Identity::language( 'post', 3, $ctx ), 'Two languages of Polylang: none.' );
		self::assertNull( AB_MCP_Sync_Identity::post( 3, $ctx ) );
		self::assertSame( 'it', AB_MCP_Sync_Identity::language( 'post', 4, $ctx ), 'WPML.' );
		self::assertSame( 'it', AB_MCP_Sync_Identity::language( 'post', 5, $ctx ), 'Two rows of one language.' );
		self::assertNull( AB_MCP_Sync_Identity::language( 'post', 6, $ctx ), 'Two languages of WPML: none.' );
		self::assertSame( 'fr', AB_MCP_Sync_Identity::language( 'post', 7, $ctx ), 'Polylang before WPML.' );
		self::assertSame( bin2hex( AB_MCP_Sync_Identity::part( array( 'attachment', '2026/11/bild.jpg', 'de' ) ) ), AB_MCP_Sync_Identity::post( 8, $ctx ), 'One file in two languages: two attachments.' );
		self::assertNotNull( AB_MCP_Sync_Identity::post( 9, $ctx ) );
		self::assertSame( bin2hex( AB_MCP_Sync_Identity::part( array( 'term', 'category', 'news', 'de' ) ) ), AB_MCP_Sync_Identity::term( 30, $ctx ) );
		self::assertSame( 'en', AB_MCP_Sync_Identity::language( 'term', 31, $ctx, 301 ) );
		self::assertSame( 'pt', AB_MCP_Sync_Identity::language( 'term', 32, $ctx, 302 ), 'WPML by the term_taxonomy_id.' );
		self::assertNull( AB_MCP_Sync_Identity::term( 33, $ctx ), 'A term of two languages: none.' );
		self::assertSame( 'nl', AB_MCP_Sync_Identity::language( 'term', 34, $ctx, 305 ), 'A term id in two taxonomies: the language of the one asked.' );
		self::assertSame( '', AB_MCP_Sync_Identity::language( 'term', 34, $ctx, 304 ) );
		self::assertNull( AB_MCP_Sync_Identity::post( 10, $ctx ), 'An attachment of two languages: none.' );
		self::assertSame( '', AB_MCP_Sync_Identity::language( 'post', 999, $ctx ), 'Not there: none.' );
	}

	public function testAMenuItemByItsTargetAndParentAndTwinsNameNone(): void {
		$s = new SyncIdentitySite();
		$s->post( 1, 'page', 'team' );
		$s->post( 2, 'page', '' );
		$s->term( 30, 300, 'category', 'news' );
		$item = static fn( string $type, string $object, int $of, int $parent, string $url = '' ): array => array( '_menu_item_type' => $type, '_menu_item_object' => $object, '_menu_item_object_id' => $of, '_menu_item_url' => $url, '_menu_item_menu_item_parent' => $parent );
		$s->item( 40, 'Team', $item( 'post_type', 'page', 1, 0 ) );
		$s->item( 41, 'News', $item( 'taxonomy', 'category', 30, 40 ) );
		$s->item( 42, 'Privat', $item( 'custom', 'custom', 0, 0, 'https://live.example/#privat' ) );
		$s->item( 43, 'Privat', $item( 'custom', 'custom', 0, 0, 'https://live.example/#privat' ), 'footer' );
		$s->item( 44, 'Firma', $item( 'custom', 'custom', 0, 0, '#' ) );
		$s->item( 45, 'Firma', $item( 'custom', 'custom', 0, 0, '#' ) );
		$s->item( 46, 'Unter', $item( 'custom', 'custom', 0, 45, 'https://example.org/unter' ) );
		$s->item( 47, 'Entwurf', $item( 'post_type', 'page', 2, 0 ) );
		$s->item( 48, 'Archiv', $item( 'post_type_archive', 'product', 0, 0 ) );
		$s->item( 49, 'Anderswo', $item( 'post_type', 'page', 1, 999 ) );
		$s->post( 3, 'attachment', 'bild' );
		$s->meta( 3, '_wp_attached_file', '2026/11/bild.jpg' );
		$s->item( 50, 'Bild', $item( 'post_type', 'attachment', 3, 0 ) );
		$s->post( 4, 'attachment', 'ohne-datei' );
		$s->item( 51, 'Ohne Datei', $item( 'post_type', 'attachment', 4, 0 ) );
		self::serve( $s );
		$ctx  = self::ctx();
		$line = static fn( int $id ): ?array => AB_MCP_Sync_Identity::menu_item( $id, $ctx );
		self::assertSame( array( AB_MCP_Sync_Identity::post( 1, $ctx ), AB_MCP_Sync_Identity::top() ), $line( 40 ), 'A page by its identity, at the top.' );
		self::assertSame( array( AB_MCP_Sync_Identity::term( 30, $ctx ), AB_MCP_Sync_Identity::post( 40, $ctx ) ), $line( 41 ), 'A term, under its parent item.' );
		self::assertNotNull( AB_MCP_Sync_Identity::post( 41, $ctx ) );
		$acc = AB_MCP_Sync_Canon::acc();
		self::assertSame( bin2hex( AB_MCP_Sync_Identity::part( array( 'custom', AB_MCP_Sync_Canon::text( 'https://live.example/#privat', $ctx, array(), $acc, 'meta:_menu_item_url', true ), 'Privat' ) ) ), $line( 42 )[0], 'A custom link by its neutral address and its label.' );
		self::assertNotNull( AB_MCP_Sync_Identity::post( 42, $ctx ), 'The same link in another menu is another item.' );
		self::assertNotSame( AB_MCP_Sync_Identity::post( 42, $ctx ), AB_MCP_Sync_Identity::post( 43, $ctx ) );
		self::assertNull( AB_MCP_Sync_Identity::post( 44, $ctx ), 'Two headings of one address and label under one parent.' );
		self::assertNotNull( $line( 44 ), 'Their line is there …' );
		self::assertSame( $line( 44 ), $line( 45 ) );
		self::assertTrue( AB_MCP_Sync_Identity::twin( array( 'kind' => 'menu_item', 'ref' => '45' ), $ctx ), '… and they are twins.' );
		self::assertNull( $line( 46 ), 'Under a twin: no parent.' );
		self::assertNull( $line( 47 ), 'Showing a page without an identity.' );
		self::assertSame( bin2hex( AB_MCP_Sync_Identity::part( array( 'post_type_archive', 'product' ) ) ), $line( 48 )[0], 'Another type by its type and object.' );
		self::assertNull( $line( 49 ), 'Under an item that is not there.' );
		self::assertSame( AB_MCP_Sync_Identity::post( 3, $ctx ), $line( 50 )[0], 'Showing an attachment: its identity.' );
		self::assertNull( AB_MCP_Sync_Identity::post( 4, $ctx ), 'The one attachment without a file has none.' );
		self::assertNull( $line( 51 ), 'Showing it: no target.' );
		// Asked without a context of the run: the context of the question.
		$custom = $line( 42 )[0];
		AB_MCP_Sync_Identity::reset();
		self::assertSame( $custom, AB_MCP_Sync_Identity::menu_item( 42, $ctx )[0] );
		// Built without any: the addresses of no site.
		AB_MCP_Sync_Identity::reset();
		self::assertTrue( AB_MCP_Sync_Identity::build() );
		$none = AB_MCP_Sync_Canon::context( array() );
		$acc  = AB_MCP_Sync_Canon::acc();
		self::assertSame( bin2hex( AB_MCP_Sync_Identity::part( array( 'custom', AB_MCP_Sync_Canon::text( 'https://live.example/#privat', $none, array(), $acc, 'meta:_menu_item_url', true ), 'Privat' ) ) ), AB_MCP_Sync_Identity::menu_item( 42, $ctx )[0] );
	}

	public function testATableThatCannotBeReadIsNamedAndEveryIdentityIsNone(): void {
		foreach ( array(
			'term_relationships' => '/tt.taxonomy = BINARY/',
			'icl_translations'   => '/icl_translations/',
			'icl_translations '  => '/FROM `wp_icl_translations`/',
			'postmeta'           => '/meta_key = BINARY/',
			'posts'              => '/post_type <> \'revision\'/',
			'terms'              => '/tt.term_taxonomy_id >/',
		) as $table => $question ) {
			$db       = self::serve( self::full_site() );
			$db->fail = $question;
			$table = trim( $table );
			self::assertSame( $table, AB_MCP_Sync_Identity::build(), $table );
			self::assertNull( AB_MCP_Sync_Identity::stored(), $table . ': nothing to keep.' );
			$db->fail = null;
			self::assertNull( AB_MCP_Sync_Identity::post( 1, self::ctx() ), $table . ': every identity none.' );
			self::assertSame( $table, AB_MCP_Sync_Identity::build(), $table . ': and stays so in this call.' );
		}
		$db       = self::serve( self::full_site() );
		$db->fail = '/nav_menu_item/';
		self::assertSame( 'postmeta', AB_MCP_Sync_Identity::build(), 'The menu items.' );
	}

	/* ------------------------------------------------- in the inventory */

	public function testAnInventoryReadsNoObjectBeforeItsIndexIsDone(): void {
		$db = self::serve( self::full_site() );
		// Out of time at once: a page of the index a call.
		AB_MCP_Sync_Inventory::$clock = static fn(): float => 1000.0;
		$source = AB_MCP_Sync_Inventory::source( array( 'https://live.example', 'https://staging.example' ), 0 );
		$read   = AB_MCP_Sync_Inventory::run( $source );
		self::assertIsArray( $read );
		self::assertSame( array(), $read['objects'], 'No object before the index is done.' );
		self::assertSame( array( 'kind' => 'post', 'inner' => null ), $read['cursor'], 'From the start of the first kind.' );
		self::assertIsString( $read['index'] );
		$given = array( 'kind' => 'term', 'inner' => array( 'after' => 5 ) );
		$again = AB_MCP_Sync_Inventory::run( AB_MCP_Sync_Inventory::source( array( 'https://live.example', 'https://staging.example' ), 0, null, null, $read['index'] ), $given );
		self::assertSame( $given, $again['cursor'], 'A cursor given stays.' );
		self::assertNotSame( $read['index'], $again['index'], 'Taken up and gone on.' );
		// Only the index reads the files of the attachments by their key.
		$db->fail = '/meta_key = BINARY \'_wp_attached_file\'/';
		$failed   = AB_MCP_Sync_Inventory::run( AB_MCP_Sync_Inventory::source( array( 'https://live.example', 'https://staging.example' ), 0, null, null, $again['index'] ), $given );
		$step     = 0;
		while ( is_array( $failed ) && $step++ < 20 ) {
			$failed = AB_MCP_Sync_Inventory::run( AB_MCP_Sync_Inventory::source( array( 'https://live.example', 'https://staging.example' ), 0, null, null, $failed['index'] ), $given );
		}
		self::assertInstanceOf( \WP_Error::class, $failed, 'A table of the index that cannot be read stops the inventory.' );
		self::assertSame( 'ab_mcp_sync_unreadable', $failed->get_error_code() );
		self::assertSame( 'postmeta', $failed->get_error_data()['table'], 'Named by the table of the index.' );
	}

	public function testAnObjectOfTheInventoryAndItsLineSayWhereItIsATwin(): void {
		$s = new SyncIdentitySite();
		$s->post( 1, 'page', 'dup' );
		$s->post( 2, 'page', 'dup' );
		$s->post( 3, 'page', 'alone' );
		$s->term( 30, 300, 'category', 'news' );
		$s->term( 31, 301, 'category', 'news' );
		$s->term( 32, 302, 'nav_menu', 'main' );
		$s->term( 33, 303, 'nav_menu', 'main' );
		self::serve( $s );
		AB_MCP_Sync_Inventory::source( array( 'https://live.example', 'https://staging.example' ) );
		$object = static fn( string $kind, string $ref, array $identity = array() ): array => AB_MCP_Sync_Inventory::entry( array( 'kind' => $kind, 'ref' => $ref, 'identity' => $identity, 'locks' => array(), 'status' => 'core', 'credentials' => array( 'b9' => array(), 'mixed' => array() ) ) );
		self::assertSame( 2, $object( 'post', '2' )['twin'], 'How many objects of the site have its identity (Codex round 5).' );
		self::assertArrayNotHasKey( 'twin', $object( 'post', '3' ), 'Only where it is one.' );
		self::assertSame( 2, $object( 'term', '30', array( 'taxonomy' => 'category' ) )['twin'] );
		self::assertArrayNotHasKey( 'twin', $object( 'menu', '30', array( 'taxonomy' => 'nav_menu' ) ), 'A menu by its taxonomy.' );
		self::assertSame( 2, $object( 'menu', '32', array( 'taxonomy' => 'nav_menu' ) )['twin'], 'Two menus of one slug.' );
		$line = static fn( array $o ): array => AB_MCP_Sync_Export::line( $o + array( 'canon' => 'canon/1', 'hash' => str_repeat( 'a', 64 ), 'size' => 1, 'complete' => true, 'refs' => array(), 'possible' => array() ) );
		self::assertSame( 2, $line( $object( 'post', '1' ) )['twin'], 'The line of a twin says so, with how many.' );
		self::assertArrayNotHasKey( 'twin', $line( $object( 'post', '3' ) ) );
		self::assertSame( 2, $line( array( 'status' => 'b9' ) + $object( 'post', '1' ) )['twin'], 'Also the line that carries no more than its identity.' );
	}
}
