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
		// Codex, round 13 (A1): a page /child/, but no page /parent/child/:
		// the whole path decides, the post rule holds.
		self::post( 'child', array( 'post_type' => 'page' ) );
		$read = self::read( self::link( '/parent/child/' . $b . '/' ), array( $b => $b + 1000 ) );
		self::assertSame( array( array( $b ), self::there( '/parent/child/' . ( $b + 1000 ) . '/' ) ), array( $read['refs'], $read['content'] ), 'No page parent/child.' );
		// A page /parent/child/ (child under parent): the page, page=N.
		$top = self::post( 'parent', array( 'post_type' => 'page' ) );
		self::post( 'child', array( 'post_type' => 'page', 'post_parent' => $top ) );
		$read = self::read( self::link( '/parent/child/' . $b . '/' ), array( $b => $b + 1000 ) );
		self::assertSame( array( array(), array(), self::there( '/parent/child/' . $b . '/' ) ), array( $read['refs'], $read['spelling'], $read['content'] ), 'The page parent/child.' );
		// As get_page_by_path(): the page of the last part, each parent of
		// the part before it; a/c/c is no page of a/b/c, e/e none of e/k.
		$a  = self::post( 'aa', array( 'post_type' => 'page' ) );
		$c1 = self::post( 'cc', array( 'post_type' => 'page', 'post_parent' => $a ) );
		self::post( 'cc', array( 'post_type' => 'page', 'post_parent' => $c1 ) );
		$e = self::post( 'ee', array( 'post_type' => 'page' ) );
		self::post( 'ee', array( 'post_type' => 'page', 'post_parent' => $e ) );
		foreach ( array( '/aa/bb/cc/', '/ee/kk/' ) as $path ) {
			$read = self::read( self::link( $path . $b . '/' ), array( $b => $b + 1000 ) );
			self::assertSame( array( $b ), $read['refs'], 'No page ' . $path . ': the post.' );
		}
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
		// Codex, round 13 (B2): an encoded slug, as post_name holds it.
		self::post( '東京', array( 'post_type' => 'page', 'post_content' => 'Eins<!--nextpage-->Zwei' ) );
		foreach ( array( '/%e6%9d%b1%e4%ba%ac/2/', '/%E6%9D%B1%E4%BA%AC/2/' ) as $path ) {
			$read = self::read( self::link( $path ), array( 2 => 200 ) );
			self::assertSame( array( array(), array(), self::there( $path ) ), array( $read['refs'], $read['spelling'], $read['content'] ), $path );
		}
		// A part the core would write otherwise (a dot): not known, locks.
		$read = self::read( self::link( '/hand.buch/2/' ), array( 2 => 200 ) );
		self::assertSame( array( array(), array( 'c:html%post_id%' ) ), array( $read['refs'], $read['spelling'] ), 'Not known.' );
		// A status WP::parse_request() refuses (auto-draft: internal, out of
		// search): the page is none, the post rule holds: p=2.
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_status' => 'auto-draft' ), array( 'ID' => self::post( 'entwurf', array( 'post_type' => 'page' ) ) ) );
		$read = self::read( self::link( '/entwurf/2/' ), array( 2 => 200 ) );
		self::assertSame( array( array( 2 ), self::there( '/entwurf/200/' ) ), array( $read['refs'], $read['content'] ), 'The post rule: p=2.' );
		$wpdb->update( $wpdb->posts, array( 'post_status' => 'draft' ), array( 'post_name' => 'entwurf' ) );
		self::assertSame( array(), self::read( self::link( '/entwurf/2/' ), array( 2 => 200 ) )['refs'], 'A draft is taken: the page, page=2.' );
	}

	public function testAScriptInAPostLocksItWithItsReason(): void {
		// Codex, round 13: a script of JavaScript in content, *:script.
		$this->permalinks( '/archives/%post_id%/' );
		$page   = self::post( 'skript', array( 'post_type' => 'page', 'post_content' => '<!-- wp:html --><script>location.href="/?p=12";</script><!-- /wp:html -->' ) );
		$kind   = \AB_MCP_Sync_Db_Kinds::for_pair( 'post' );
		$result = $kind->canonical(
			array(
				'kind'   => 'post',
				'ref'    => (string) $page,
				'source' => AB_MCP_Sync_Inventory::source( AB_MCP_IT_Sync_Steps::addresses() ),
			)
		);
		self::assertIsArray( $result );
		$lock = array_values( array_filter( $result['locks'], static fn( $l ) => '*:script' === $l['key'] ) );
		self::assertCount( 1, $lock );
		self::assertSame( 'Z8d', $lock[0]['stage'] );
		self::assertStringContainsString( 'by hand', $lock[0]['reason'] );
		// JSON-LD is no script of JavaScript: nothing locks it so.
		$json   = self::post( 'daten', array( 'post_type' => 'page', 'post_content' => '<!-- wp:html --><script type="application/ld+json">{"@type":"Article","url":"' . AB_MCP_IT_Sites::url( 'a' ) . '/?p=12"}</script><!-- /wp:html -->' ) );
		$result = $kind->canonical(
			array(
				'kind'   => 'post',
				'ref'    => (string) $json,
				'source' => AB_MCP_Sync_Inventory::source( AB_MCP_IT_Sync_Steps::addresses() ),
			)
		);
		self::assertSame( array(), array_values( array_filter( $result['locks'], static fn( $l ) => '*:script' === $l['key'] ) ) );
	}

	public function testThePdfPreviewOfAFileBlockIsFree(): void {
		// The PDF of the uploads as _wp_kses_allow_pdf_objects() takes it,
		// the uploads from the rows: free, its address rewritten.
		$this->permalinks( '/archives/%post_id%/' );
		$pdf  = AB_MCP_IT_Sites::url( 'a' ) . '/wp-content/uploads/2026/11/a.pdf';
		$file = static fn( string $object ) => '<!-- wp:file {"id":12,"href":"' . $pdf . '","displayPreview":true} --><div class="wp-block-file">' . $object . '<a id="wp-block-file--media-1" href="' . $pdf . '">a</a><a href="' . $pdf . '" class="wp-block-file__button wp-element-button" download aria-describedby="wp-block-file--media-1">Herunterladen</a></div><!-- /wp:file -->';
		$free = $file( '<object class="wp-block-file__embed" data="' . $pdf . '" type="application/pdf" style="width:100%;height:600px" aria-label="Embed of a."></object>' );
		self::assertSame( 'clean', \AB_MCP_Sync_Credentials::unit( '', $free, array() )['state'] );
		self::assertSame( 'clean', \AB_MCP_Sync_Credentials::unit( '', '<object data="' . $pdf . '" type="Application/PDF"></object>', array() )['state'], 'The type in any case, as kses compares it.' );
		$read = self::read( $free, array() );
		self::assertSame( array( array(), str_replace( AB_MCP_IT_Sites::url( 'a' ), 'https://www.live.test', $free ) ), array( $read['spelling'], $read['content'] ) );
		// Another type, none, a query, outside the uploads, another host: locked.
		foreach ( array(
			'<object data="' . $pdf . '" type="text/html"></object>',
			'<object data="' . $pdf . '"></object>',
			'<object data="' . $pdf . '?x=1.pdf" type="application/pdf"></object>',
			'<object data="' . $pdf . '#x.pdf" type="application/pdf"></object>',
			'<object data="' . AB_MCP_IT_Sites::url( 'a' ) . '/wp-content/other/a.pdf" type="application/pdf"></object>',
			'<object data="https://cdn.test/wp-content/uploads/a.pdf" type="application/pdf"></object>',
			'<object data="' . substr( $pdf, 0, -4 ) . '.html" type="application/pdf"></object>',
		) as $object ) {
			$check = \AB_MCP_Sync_Credentials::unit( '', $file( $object ), array() );
			self::assertSame( array( 'unreadable', 'html_outside_kses' ), array( $check['state'], $check['why'] ?? null ), $object );
		}
		// upload_url_path from its row: the uploads there.
		update_option( 'upload_url_path', 'https://media.test/files' );
		self::assertSame( 'clean', \AB_MCP_Sync_Credentials::unit( '', '<object data="https://media.test/files/a.pdf" type="application/pdf"></object>', array() )['state'] );
		self::assertSame( 'unreadable', \AB_MCP_Sync_Credentials::unit( '', $free, array() )['state'], 'The uploads are elsewhere now.' );
		// A row that holds no text: not known, locked.
		update_option( 'upload_url_path', array( 'x' ) );
		self::assertSame( 'unreadable', \AB_MCP_Sync_Credentials::unit( '', '<object data="https://media.test/files/a.pdf" type="application/pdf"></object>', array() )['state'] );
		self::assertNull( \AB_MCP_Sync_Rows::uploads_url() );
		delete_option( 'upload_url_path' );
		update_option( 'upload_path', 'media' );
		self::assertSame( 'clean', \AB_MCP_Sync_Credentials::unit( '', '<object data="' . AB_MCP_IT_Sites::url( 'a' ) . '/media/a.pdf" type="application/pdf"></object>', array() )['state'], 'upload_path under siteurl.' );
		update_option( 'upload_path', ABSPATH . 'wp-content/uploads' );
		self::assertSame( 'clean', \AB_MCP_Sync_Credentials::unit( '', $free, array() )['state'], 'An absolute directory: the uploads of WP_CONTENT_URL.' );
		// Codex, round 17 (B1): an absolute directory outside ABSPATH, as
		// path_join() leaves it: the uploads of WP_CONTENT_URL as well.
		update_option( 'upload_path', '/srv/shared/uploads' );
		self::assertSame( 'clean', \AB_MCP_Sync_Credentials::unit( '', $free, array() )['state'], 'upload_path=/srv/shared/uploads' );
		update_option( 'upload_path', './media' );
		self::assertSame( 'unreadable', \AB_MCP_Sync_Credentials::unit( '', $free, array() )['state'], 'Relative: under siteurl.' );
		// As _wp_upload_dir() of the core makes it, for each spelling; siteurl
		// as get_option() gives it (WP_SITEURL of the site, not its row).
		$siteurl = get_option( 'siteurl' );
		$wpdb    = $GLOBALS['wpdb'];
		$wpdb->update( $wpdb->options, array( 'option_value' => 'https://row.test' ), array( 'option_name' => 'siteurl' ) );
		try {
			foreach ( array( '', '0', ' wp-content/uploads ', 'media', 'media/files/', './media', '../shared', '/srv/shared/uploads', '\\\\srv\\uploads', 'C:\\uploads', 'C:/uploads', ABSPATH . 'wp-content/uploads' ) as $path ) {
				foreach ( array( '', '0', 'https://media.test/files/' ) as $url ) {
					update_option( 'upload_path', $path );
					update_option( 'upload_url_path', $url );
					self::assertSame( rtrim( _wp_upload_dir()['baseurl'], '/' ), \AB_MCP_Sync_Rows::uploads_url(), 'upload_path=' . $path . ' upload_url_path=' . $url );
				}
			}
		} finally {
			$wpdb->update( $wpdb->options, array( 'option_value' => $siteurl ), array( 'option_name' => 'siteurl' ) );
		}
		// A stream: only the file system tells whether it is absolute.
		update_option( 'upload_path', 'php://memory' );
		self::assertNull( \AB_MCP_Sync_Rows::uploads_url() );
		delete_option( 'upload_path' );
		delete_option( 'upload_url_path' );
	}

	public function testAdditionalCssIsReadAsCss(): void {
		// Codex, round 16 (A5, C1): custom_css with a credential, an address
		// without a host in url(…).
		$this->permalinks( '/archives/%post_id%/' );
		$post   = self::post( 'ziel' );
		$canon  = static function (): array {
			return \AB_MCP_Sync_Db_Kinds::for_pair( 'custom_css' )->canonical(
				array(
					'kind'   => 'custom_css',
					'ref'    => get_stylesheet(),
					'source' => AB_MCP_Sync_Inventory::source( AB_MCP_IT_Sync_Steps::addresses() ),
				)
			);
		};
		wp_update_custom_css_post( '.x { --api-key: demo-secret; }' );
		self::assertContains( '*:credential_field', array_column( $canon()['locks'], 'key' ) );
		wp_update_custom_css_post( 'q::before { content: "\\201C"; } body { background: url("/?p=' . $post . '"); }' );
		$result = $canon();
		self::assertSame( array( array( $post ), array() ), array( array_column( $result['refs'], 'id' ), array_column( $result['locks'], 'key' ) ) );
		// Codex, round 17 (A3, A4, A7): comments, data:, @import "…".
		wp_update_custom_css_post( '@import url("data:text/css;base64,OnJvb3R7LS1hcGkta2V5OmRlbW8tc2VjcmV0fQ==");' );
		self::assertSame( array( '*:reference_spelling', '*:unreadable_value' ), self::keys( $canon()['locks'] ) );
		wp_update_custom_css_post( '.x{--api-key/**/:demo-secret}' );
		self::assertSame( array( '*:credential_field' ), self::keys( $canon()['locks'] ) );
		wp_update_custom_css_post( "/* Kopfzeile */\nq::before { content: \"\\201C\"; } /* Ende */\n@import \"/?p=" . $post . "\";" );
		$result = $canon();
		self::assertSame( array( array( $post ), array() ), array( array_column( $result['refs'], 'id' ), array_column( $result['locks'], 'key' ) ) );
	}

	public function testGlobalStylesAreReadAsCss(): void {
		// Codex, round 17 (A2): the CSS of global styles (wp_global_styles,
		// its key css) as CSS; an absolute address of an image is free.
		$this->permalinks( '/archives/%post_id%/' );
		$post   = self::post( 'ziel' );
		$styles = static function ( array $styles ): int {
			return self::post( 'wp-global-styles-' . wp_generate_password( 6, false ), array( 'post_type' => 'wp_global_styles', 'post_content' => wp_slash( (string) wp_json_encode( array( 'version' => 3, 'isGlobalStylesUserThemeJSON' => true, 'styles' => $styles ) ) ) ) );
		};
		$canon  = static function ( int $id ): array {
			return \AB_MCP_Sync_Db_Kinds::for_pair( 'post' )->canonical(
				array(
					'kind'   => 'post',
					'ref'    => (string) $id,
					'source' => AB_MCP_Sync_Inventory::source( AB_MCP_IT_Sync_Steps::addresses() ),
				)
			);
		};
		$posts  = static fn( array $result ): array => array_column( array_filter( $result['refs'], static fn( $r ) => 'post' === $r['space'] ), 'id' );
		$locked = $canon( $styles( array( 'css' => '.x{background:url(/?p=' . $post . ')}' ) ) );
		self::assertSame( array( array(), array( '*:reference_spelling' ) ), array( $posts( $locked ), self::keys( $locked['locks'] ) ) );
		$locked = $canon( $styles( array( 'blocks' => array( 'core/paragraph' => array( 'css' => '.x{--api/**/-key:demo-secret}' ) ) ) ) );
		self::assertSame( array( '*:credential_field' ), self::keys( $locked['locks'] ) );
		$free = $canon( $styles( array( 'background' => array( 'backgroundImage' => array( 'url' => AB_MCP_IT_Sites::url( 'a' ) . '/wp-content/uploads/bg.jpg' ) ), 'css' => '.x{background:url(' . AB_MCP_IT_Sites::url( 'a' ) . '/wp-content/uploads/a.png)}' ) ) );
		self::assertSame( array( array(), array() ), array( $posts( $free ), self::keys( $free['locks'] ) ) );
	}

	/** @return string[] The keys of the locks, each once, sorted. */
	private static function keys( array $locks ): array {
		$keys = array_values( array_unique( array_column( $locks, 'key' ) ) );
		sort( $keys );
		return $keys;
	}

	public function testTheContextAsTheCoreWritesItIsRewritten(): void {
		// Codex, round 18 (A3, B2): the output of the helper of the core, each
		// / as \/, with a namespace and without, relative and absolute.
		$this->permalinks( '/archives/%post_id%/' );
		$a = self::post( 'eins' );
		foreach ( array( '', 'acme/store' ) as $ns ) {
			foreach ( array( '/?p=' . $a, AB_MCP_IT_Sites::url( 'a' ) . '/?p=' . $a, AB_MCP_IT_Sites::url( 'a' ) . '/archives/' . $a . '/' ) as $url ) {
				$attr = wp_interactivity_data_wp_context( array( 'url' => $url, 'open' => false ), $ns );
				self::assertStringContainsString( '\\/', $attr, 'The core writes / as \\/.' );
				$read = self::read( '<div data-wp-interactive="acme" ' . $attr . '><a data-wp-bind--href="context.url">x</a></div>', array( $a => $a + 1000 ) );
				$to   = wp_interactivity_data_wp_context( array( 'url' => str_replace( array( AB_MCP_IT_Sites::url( 'a' ), (string) $a ), array( 'https://www.live.test', (string) ( $a + 1000 ) ), $url ), 'open' => false ), $ns );
				self::assertSame( array( array( $a ), array(), '<div data-wp-interactive="acme" ' . $to . '><a data-wp-bind--href="context.url">x</a></div>', array() ), array( $read['refs'], $read['spelling'], $read['content'], $read['missing'] ), $attr );
			}
		}
		// Every other escape of the helper (& as \u0026): locked.
		$read = self::read( '<div ' . wp_interactivity_data_wp_context( array( 'url' => '/?a=1&p=' . $a ) ) . '>x</div>', array( $a => $a + 1000 ) );
		self::assertSame( array( array(), array( 'c:html?p' ) ), array( $read['refs'], $read['spelling'] ) );
		self::assertSame( 'mixed', \AB_MCP_Sync_Credentials::unit( '', '<div ' . wp_interactivity_data_wp_context( array( array( 'name' => 'api_key', 'value' => 'demo-secret' ) ), 'acme/store' ) . '></div>', array() )['state'] );
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
			$routes  = \AB_MCP_Sync_Urls::routes();
			$uploads = \AB_MCP_Sync_Rows::uploads_url();
		} finally {
			$wpdb->options = $table;
			$wpdb->suppress_errors( $suppress );
		}
		self::assertNull( $routes, 'Not read is not known: a segment of digits locks.' );
		self::assertNull( $uploads, 'Not read: no PDF of the uploads.' );
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
