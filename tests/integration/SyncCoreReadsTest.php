<?php
/**
 * The cases of SyncCoreReads read by the core of WordPress 7.1.3: the HTML
 * API, WP_HTML_Decoder, parse_str(); here the answer of each case itself
 * (in the unit suite those with a tag or a character reference fail
 * closed). And the paths as the rewrite rules of this site, from its rows,
 * route them under the permalink structures of the core (Codex, round 11:
 * the search of url_to_postid() over the rules, no query): digits a rule
 * puts into p, page_id … name that object, digits in page, year … none.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests\Integration;

use AB_MCP_IT_Sites;
use AB_MCP_IT_Sync_Steps;
use AB_MCP_Sync_Canon;
use AB_MCP_Sync_Inventory;
use AB_MCP_Sync_Refs;
use AlphaBridge\Tests\SyncCoreReads;
use PHPUnit\Framework\TestCase;

final class SyncCoreReadsTest extends TestCase {

	use SyncCoreReads;

	protected static function core(): bool {
		return true;
	}

	/** Whether a test wrote to site A (the cases of SyncCoreReads read only). */
	private bool $wrote = false;

	protected function tearDown(): void {
		global $wp_rewrite;
		if ( $this->wrote ) {
			AB_MCP_IT_Sites::reset( 'a' );
			$wp_rewrite->init();
			wp_set_current_user( 0 );
		}
	}

	public function testThisSuiteHasTheCore(): void {
		self::assertTrue( class_exists( 'WP_HTML_Tag_Processor' ) && class_exists( 'WP_HTML_Decoder' ) && function_exists( 'url_to_postid' ) );
	}

	/**
	 * The permalinks of this site, as the settings page sets them; the
	 * taxonomies registered again, as on the next request, so that their
	 * tags (%category%) are rewrite tags.
	 */
	private function permalinks( string $structure ): void {
		global $wp_rewrite;
		if ( ! $this->wrote ) {
			$this->wrote = true;
			AB_MCP_IT_Sites::reset( 'a' );
			wp_set_current_user( (int) AB_MCP_IT_Sites::site( 'a' )['admin']['user_id'] );
		}
		$wp_rewrite->set_permalink_structure( $structure );
		create_initial_taxonomies();
		$wp_rewrite->flush_rules( false );
	}

	private static function post( string $slug, array $more = array() ): int {
		$id = wp_insert_post(
			array_merge(
				array(
					'post_title'   => $slug,
					'post_name'    => $slug,
					'post_content' => 'Ziel',
					'post_status'  => 'publish',
					'post_type'    => 'post',
				),
				$more
			),
			true
		);
		self::assertIsInt( $id );
		return $id;
	}

	/** The references and the places that lock of a text on this site, and its rewrite. */
	private static function read( string $content, array $map ): array {
		$source = AB_MCP_Sync_Inventory::source( AB_MCP_IT_Sync_Steps::addresses() );
		$acc    = AB_MCP_Sync_Canon::acc();
		AB_MCP_Sync_Refs::content( $content, $source['ctx'], $acc, 'post', 'c' );
		$plan = AB_MCP_Sync_Refs::plan( array( 'post' => $map ), array( AB_MCP_IT_Sites::url( 'a' ) => 'https://www.live.test' ), $source['ctx']['routes'] );
		$out  = AB_MCP_Sync_Refs::rewrite_content( $content, $plan );
		return array(
			'refs'     => array_column( $acc['refs'], 'id' ),
			'spelling' => $acc['spelling'],
			'content'  => $out['content'],
			'missing'  => array_column( $out['missing'], 'space' ),
		);
	}

	private static function link( string $path ): string {
		return '<p><a href="' . AB_MCP_IT_Sites::url( 'a' ) . $path . '">x</a></p>';
	}

	private static function there( string $path ): string {
		return '<p><a href="https://www.live.test' . $path . '">x</a></p>';
	}

	public function testThePathsOfTheNumericPermalinksAreReferences(): void {
		$this->permalinks( '/archives/%post_id%/' );
		$a = self::post( 'eins' );
		$b = self::post( 'zwei' );
		foreach ( array(
			'/archives/' . $a . '/'                  => '/archives/' . ( $a + 1000 ) . '/',
			'/archives/' . $a                        => '/archives/' . ( $a + 1000 ),
		) as $path => $to ) {
			$read = self::read( self::link( $path ), array( $a => $a + 1000, $b => $b + 1000 ) );
			self::assertSame( array( array( $a ), array(), self::there( $to ), array() ), array( $read['refs'], $read['spelling'], $read['content'], $read['missing'] ), $path );
		}
		// Codex, round 10 (7): dot segments resolve to another post; the core
		// is asked about what the browser resolves, and the form locks.
		$escaped = implode( '', array_map( static fn( $d ) => '%3' . $d, str_split( (string) $b ) ) );
		foreach ( array( '/archives/' . $a . '/../' . $b . '/', '/archives/./' . $b . '/', '/archives/' . $escaped . '/', '/archives/0' . $b . '/' ) as $path ) {
			$read = self::read( self::link( $path ), array( $a => $a + 1000, $b => $b + 1000 ) );
			self::assertSame( array( array(), array( 'c:html%post_id%' ) ), array( $read['refs'], $read['spelling'] ), $path );
			self::assertContains( 'spelling', $read['missing'], $path );
		}
		// Codex, round 10 (9): a file of the uploads names no post.
		$read = self::read( '<p><img src="/wp-content/uploads/2026/11/photo' . $a . '.jpg"> <img src="photo2.jpg"></p>', array( $a => $a + 1000 ) );
		self::assertSame( array( array(), array() ), array( $read['refs'], $read['spelling'] ) );
	}

	public function testTheCoreResolvesRoutesTheStructureAloneDoesNotShow(): void {
		// Codex, round 10 (6): /%post_id%/%postname%/ also routes /<id>/.
		$this->permalinks( '/%post_id%/%postname%/' );
		$a = self::post( 'eins' );
		foreach ( array( '/' . $a . '/', '/' . $a . '/eins/' ) as $path ) {
			$read = self::read( self::link( $path ), array( $a => $a + 1000 ) );
			self::assertSame( array( array( $a ), self::there( str_replace( (string) $a, (string) ( $a + 1000 ), $path ) ) ), array( $read['refs'], $read['content'] ), $path );
		}
		// Codex, round 10 (6): a hierarchical category spans two segments.
		$this->permalinks( '/%category%/%post_id%/' );
		$parent = wp_insert_term( 'Eltern', 'category', array( 'slug' => 'parent' ) );
		$child  = wp_insert_term( 'Kind', 'category', array( 'slug' => 'child', 'parent' => $parent['term_id'] ) );
		$b      = self::post( 'zwei', array( 'post_category' => array( $child['term_id'] ) ) );
		$read   = self::read( self::link( '/parent/child/' . $b . '/' ), array( $b => $b + 1000 ) );
		self::assertSame( array( array( $b ), self::there( '/parent/child/' . ( $b + 1000 ) . '/' ) ), array( $read['refs'], $read['content'] ) );
	}

	public function testANumberOfAPostIsNoDateArchiveWhereTheCoreReadsAPost(): void {
		// Codex, round 10 (8): under /%post_id%/ the core reads /2026/ as p=2026.
		$this->permalinks( '/%post_id%/' );
		$year = self::post( 'jahr', array( 'import_id' => 2026 ) );
		self::assertSame( 2026, $year, 'The id is free on the test site.' );
		$read = self::read( self::link( '/2026/' ), array( 2026 => 3026 ) );
		self::assertSame( array( array( 2026 ), array(), self::there( '/3026/' ) ), array( $read['refs'], $read['spelling'], $read['content'] ) );
		// And /%year%/%post_id%/: year and post.
		$this->permalinks( '/%year%/%post_id%/' );
		$b    = self::post( 'zwei', array( 'post_date' => '2026-11-03 10:00:00' ) );
		$read = self::read( self::link( '/2026/' . $b . '/' ), array( $b => $b + 1000 ) );
		self::assertSame( array( array( $b ), self::there( '/2026/' . ( $b + 1000 ) . '/' ) ), array( $read['refs'], $read['content'] ) );
	}

	public function testADatePermalinkNamesNoId(): void {
		// Codex, round 9: the date of a post is no id; the host is replaced.
		$this->permalinks( '/%year%/%monthnum%/%day%/%postname%/' );
		$post = self::post( 'beitrag', array( 'post_date' => '2026-11-03 10:00:00' ) );
		$read = self::read( self::link( '/2026/11/03/beitrag/' ), array( $post => $post + 1000 ) );
		self::assertSame( array( array(), array(), self::there( '/2026/11/03/beitrag/' ), array() ), array( $read['refs'], $read['spelling'], $read['content'], $read['missing'] ) );
	}

	public function testTheYearOfAPostIsNoIdWhereItEqualsOne(): void {
		// The rule puts 2026 into year, not into p: no id, even of post 2026.
		$this->permalinks( '/%year%/%postname%/' );
		$post = self::post( 'jahr', array( 'import_id' => 2026, 'post_date' => '2026-11-03 10:00:00' ) );
		self::assertSame( 2026, $post );
		$read = self::read( self::link( '/2026/jahr/' ), array( 2026 => 3026 ) );
		self::assertSame( array( array(), array(), self::there( '/2026/jahr/' ) ), array( $read['refs'], $read['spelling'], $read['content'] ) );
		// A link by its slug names no id: the host is replaced, nothing locks.
		$other = self::post( 'anderer', array( 'post_date' => '2026-11-03 10:00:00' ) );
		$read  = self::read( self::link( '/2026/anderer/' ), array( $other => $other + 1000 ) );
		self::assertSame( array( array(), array(), self::there( '/2026/anderer/' ) ), array( $read['refs'], $read['spelling'], $read['content'] ) );
	}

	public function testAPartOfAPageIsNoId(): void {
		// Codex, round 11 (2): /handbuch/2/ is the second part of the page,
		// page=2, also where a post or the page has that id.
		$this->permalinks( '/archives/%post_id%/' );
		$page = self::post( 'handbuch', array( 'post_type' => 'page', 'import_id' => 2002, 'post_content' => 'Eins<!--nextpage-->Zwei' ) );
		self::assertSame( 2002, $page, 'The id is free on the test site.' );
		foreach ( array( '/handbuch/2/', '/handbuch/2002/' ) as $path ) {
			$read = self::read( self::link( $path ), array( 2 => 200, 2002 => 3002 ) );
			self::assertSame( array( array(), array(), self::there( $path ), array() ), array( $read['refs'], $read['spelling'], $read['content'], $read['missing'] ), $path );
		}
		// The verbose rules of pages hold where the page exists.
		$this->permalinks( '/%postname%/%post_id%/' );
		$post = self::post( 'beitrag' );
		$read = self::read( self::link( '/beitrag/' . $post . '/' ), array( $post => $post + 1000 ) );
		self::assertSame( array( array( $post ), self::there( '/beitrag/' . ( $post + 1000 ) . '/' ) ), array( $read['refs'], $read['content'] ), 'No page beitrag: the post.' );
		// Codex, round 12 (7): the page handbuch exists, the search ends
		// there: pagename=handbuch&page=2, no id.
		$read = self::read( self::link( '/handbuch/2/' ), array( 2 => 200 ) );
		self::assertSame( array( array(), array(), self::there( '/handbuch/2/' ) ), array( $read['refs'], $read['spelling'], $read['content'] ), 'The page handbuch exists.' );
		// A name the rows are not asked about (not a plain slug): page or
		// post, not known: locks.
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_name' => 'caf%c3%a9' ), array( 'ID' => self::post( 'cafe', array( 'post_type' => 'page' ) ) ) );
		$read = self::read( self::link( '/caf%C3%A9/2/' ), array( 2 => 200 ) );
		self::assertSame( array( array(), array( 'c:html%post_id%' ) ), array( $read['refs'], $read['spelling'] ), 'Not known.' );
	}

	public function testAnotherCharsetThanUtf8IsNotRead(): void {
		// Codex, round 12 (1): the charset from its row; not UTF-8, not read.
		$this->permalinks( '/archives/%post_id%/' );
		$a = self::post( 'eins' );
		update_option( 'blog_charset', 'ISO-8859-1' );
		$read = self::read( self::link( '/?p=' . $a ), array( $a => $a + 1000 ) );
		self::assertSame( array( array(), array( 'c:html:tags' ) ), array( $read['refs'], $read['spelling'] ) );
		update_option( 'blog_charset', 'utf8' );
		self::assertSame( array( $a ), self::read( self::link( '/?p=' . $a ), array( $a => $a + 1000 ) )['refs'] );
	}

	public function testRowsThatCannotBeReadAreNotKnown(): void {
		global $wpdb;
		$table         = $wpdb->options;
		$wpdb->options = $table . '_ab_it_missing';
		$suppress      = $wpdb->suppress_errors( true );
		try {
			$routes = \AB_MCP_Sync_Urls::routes();
		} finally {
			$wpdb->options = $table;
			$wpdb->suppress_errors( $suppress );
		}
		self::assertNull( $routes, 'Not read is not known: a segment of digits locks.' );
	}

	public function testUnderPlainPermalinksNoPathNamesAnId(): void {
		$this->permalinks( '' );
		$a    = self::post( 'eins' );
		$read = self::read( self::link( '/2026/11/photo' . $a . '.jpg' ) . self::link( '/archives/' . $a . '/' ), array( $a => $a + 1000 ) );
		self::assertSame( array( array(), array() ), array( $read['refs'], $read['spelling'] ) );
	}

	public function testThePermalinksWithIndexPhp(): void {
		$this->permalinks( '/index.php/archives/%post_id%/' );
		$a    = self::post( 'eins' );
		$read = self::read( self::link( '/index.php/archives/' . $a . '/' ), array( $a => $a + 1000 ) );
		self::assertSame( array( array( $a ), self::there( '/index.php/archives/' . ( $a + 1000 ) . '/' ) ), array( $read['refs'], $read['content'] ) );
	}

	public function testWithoutTheRowOfTheRulesADigitLocks(): void {
		global $wpdb;
		$this->permalinks( '/archives/%post_id%/' );
		$a = self::post( 'eins' );
		foreach ( array( serialize( array() ), serialize( array( 'archives/([0-9]+)/?$' => array( 'p' ) ) ) ) as $rules ) {
			$wpdb->update( $wpdb->options, array( 'option_value' => $rules ), array( 'option_name' => 'rewrite_rules' ) );
			$read = self::read( self::link( '/archives/' . $a . '/' ), array( $a => $a + 1000 ) );
			self::assertSame( array( array(), array( 'c:html%post_id%' ) ), array( $read['refs'], $read['spelling'] ), 'Rules not read: ' . $rules );
		}
		$wpdb->delete( $wpdb->options, array( 'option_name' => 'rewrite_rules' ) );
		$read = self::read( self::link( '/archives/' . $a . '/' ), array( $a => $a + 1000 ) );
		self::assertSame( array( array(), array( 'c:html%post_id%' ) ), array( $read['refs'], $read['spelling'] ), 'Not read is not known.' );
		$wpdb->insert( $wpdb->options, array( 'option_name' => 'rewrite_rules', 'option_value' => serialize( array( 'archives/([0-9]+)/?$' => new \stdClass() ) ), 'autoload' => 'off' ) );
		$read = self::read( self::link( '/archives/' . $a . '/' ), array( $a => $a + 1000 ) );
		self::assertSame( array( array(), array( 'c:html%post_id%' ) ), array( $read['refs'], $read['spelling'] ), 'An object in the rules: not read.' );
	}

	public function testAnotherSpellingOfThisSiteIsTheSameSiteAndLocks(): void {
		// Codex, round 10 (5): url_to_postid() compares hosts without "www.".
		$this->permalinks( '/archives/%post_id%/' );
		$a    = self::post( 'eins' );
		$url  = str_replace( '://', '://www.', AB_MCP_IT_Sites::url( 'a' ) );
		$read = self::read( '<p><a href="' . $url . '/archives/' . $a . '/">x</a> <a href="' . $url . '/?p=' . $a . '">y</a></p>', array( $a => $a + 1000 ) );
		self::assertSame( array( array(), array( 'c:html%post_id%', 'c:html?p' ) ), array( $read['refs'], $read['spelling'] ) );
	}
}
