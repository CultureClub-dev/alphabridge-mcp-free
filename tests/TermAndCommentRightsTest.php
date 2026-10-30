<?php
/**
 * The right to the one object, not only to its kind. wp-admin changes and
 * deletes a term only with edit_term or delete_term for its id, assigns one
 * (through the REST API of the block editor) only with assign_term for it,
 * and moderates, deletes or answers a comment only with edit_comment for it
 * or edit_post for its post. map_meta_cap lets a site withhold single
 * objects from an account that may handle the others, so a check of the
 * taxonomy's right or of moderate_comments alone would pass them.
 *
 * Each case withholds exactly one right for exactly one id, and checks
 * that the tool refuses that object and writes nothing, and that it works
 * for the next one. A default term is kept as wp-admin keeps it, with the
 * answer that names the default term instead of a missing right; a term id
 * below 1, which WordPress would read as its absolute value, names no term.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Tools_Content;
use AB_MCP_Tools_Taxonomy_Comments;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

require_once __DIR__ . '/term-rights-stubs.php';

final class TermAndCommentRightsTest extends TestCase {

	protected function setUp(): void {
		ab_test_reset();
		$GLOBALS['ab_test_way']         = array(
			'taxonomies' => array(
				'category' => (object) array(
					'name' => 'category',
					'cap'  => (object) array(
						'manage_terms' => 'manage_categories',
						'edit_terms'   => 'manage_categories',
						'delete_terms' => 'manage_categories',
						'assign_terms' => 'edit_posts',
					),
				),
			),
			'terms'      => array(
				3 => (object) array( 'term_id' => 3, 'taxonomy' => 'category' ),
				4 => (object) array( 'term_id' => 4, 'taxonomy' => 'category' ),
			),
		);
		$GLOBALS['ab_test_term_writes'] = array();
	}

	/**
	 * Every right passes, except $cap for the one id $id, and, as
	 * map_meta_cap answers it (do_not_allow), a right to a term that does
	 * not exist.
	 */
	private static function withhold( string $cap, int $id ): void {
		$GLOBALS['ab_test_can'] = static function ( string $asked, array $args ) use ( $cap, $id ): bool {
			$obj = (int) ( $args[0] ?? 0 );
			if ( in_array( $asked, array( 'edit_term', 'delete_term', 'assign_term' ), true ) && ! isset( $GLOBALS['ab_test_way']['terms'][ $obj ] ) ) {
				return false;
			}
			return ! ( $asked === $cap && $obj === $id );
		};
	}

	private static function assertRefused( $res, string $what ): void {
		self::assertInstanceOf( \WP_Error::class, $res );
		self::assertSame( 'ab_mcp_forbidden', $res->get_error_code() );
		self::assertStringContainsString( $what, (string) $res->get_error_message() );
	}

	/* ------------------------------------------------------------------ terms */

	public function testATermWithheldFromTheAccountIsNotChanged(): void {
		self::withhold( 'edit_term', 3 );
		$res = AB_MCP_Tools_Taxonomy_Comments::update_term( array( 'taxonomy' => 'category', 'term_id' => 3, 'name' => 'Neu' ) );
		self::assertRefused( $res, 'cannot edit this specific term' );
		self::assertSame( array(), $GLOBALS['ab_test_term_writes'] );

		$res = AB_MCP_Tools_Taxonomy_Comments::update_term( array( 'taxonomy' => 'category', 'term_id' => 4, 'name' => 'Neu' ) );
		self::assertSame( array( 'updated' => true, 'term_id' => 4 ), array_slice( $res, 0, 2 ) );
		self::assertSame( array( 'name' => 'Neu' ), (array) $res['saved_fields'], 'Read again after the write (4.13.0).' );
		self::assertSame( array( array( 'update', 4, 'category' ) ), $GLOBALS['ab_test_term_writes'] );
	}

	public function testAnIdOfNoTermOfTheTaxonomyIsLeftToWordPress(): void {
		// edit_term answers no for an unknown id, as map_meta_cap does
		// (do_not_allow); the answer must not blame a missing right, but
		// pass on WordPress's own refusal.
		$GLOBALS['ab_test_can'] = static fn( string $asked ): bool => 'edit_term' !== $asked;
		$res = AB_MCP_Tools_Taxonomy_Comments::update_term( array( 'taxonomy' => 'category', 'term_id' => 99, 'name' => 'Neu' ) );
		self::assertSame( 'invalid_term', $res->get_error_code() );

		// Nor for a term of another taxonomy.
		$GLOBALS['ab_test_way']['terms'][5] = (object) array( 'term_id' => 5, 'taxonomy' => 'post_tag' );
		$res = AB_MCP_Tools_Taxonomy_Comments::update_term( array( 'taxonomy' => 'category', 'term_id' => 5, 'name' => 'Neu' ) );
		self::assertSame( 'invalid_term', $res->get_error_code() );
		self::assertSame( array(), $GLOBALS['ab_test_term_writes'] );
	}

	public function testANumberBelowOneNamesNoTerm(): void {
		// WordPress would look -3 up as term 3 (term_exists()), past the
		// right withheld for it.
		self::withhold( 'delete_term', 3 );
		$GLOBALS['ab_test_way']['term_deleted'] = true;
		foreach ( array( 'update_term', 'delete_term' ) as $tool ) {
			$res = AB_MCP_Tools_Taxonomy_Comments::$tool( array( 'taxonomy' => 'category', 'term_id' => -3, 'name' => 'Neu' ) );
			self::assertSame( 'ab_mcp_not_found', $res->get_error_code(), $tool );
		}

		self::withhold( 'assign_term', 3 );
		$apply = new ReflectionMethod( AB_MCP_Tools_Content::class, 'apply_meta_and_terms' );
		$apply->invoke( null, 10, array( 'terms' => array( 'category' => array( -3, 4 ) ) ) );
		self::assertSame( array( array( 'set', 10, 'category', array( 4 ) ) ), $GLOBALS['ab_test_term_writes'] );
	}

	public function testATermWithheldFromTheAccountIsNotDeleted(): void {
		self::withhold( 'delete_term', 3 );
		$GLOBALS['ab_test_way']['term_deleted'] = true;
		$res = AB_MCP_Tools_Taxonomy_Comments::delete_term( array( 'taxonomy' => 'category', 'term_id' => 3 ) );
		self::assertRefused( $res, 'cannot delete this specific term' );

		$res = AB_MCP_Tools_Taxonomy_Comments::delete_term( array( 'taxonomy' => 'category', 'term_id' => 4 ) );
		self::assertSame( array( 'deleted' => true, 'undo' => null ), array_slice( $res, 0, 2 ) );
		self::assertStringStartsWith( 'not undoable: ', $res['undo_note'] );
		self::assertStringContainsString( 'wp_get_term', $res['undo_note'] );
	}

	public function testTheDefaultTermStillNamesTheDefaultTerm(): void {
		// map_meta_cap answers do_not_allow for delete_term on the default
		// category; WordPress keeps it, and the answer says where it changes.
		$GLOBALS['ab_test_options']['default_category'] = 3;
		self::withhold( 'delete_term', 3 );
		$GLOBALS['ab_test_way']['term_deleted'] = true; // Not reached: it would answer deleted.
		$res = AB_MCP_Tools_Taxonomy_Comments::delete_term( array( 'taxonomy' => 'category', 'term_id' => 3 ) );
		self::assertSame( 'ab_mcp_delete_failed', $res->get_error_code() );
		self::assertStringContainsString( 'Settings → Writing', (string) $res->get_error_message() );
	}

	public function testEveryDefaultTermIsKeptAsInWpAdmin(): void {
		// wp_delete_term() spares only the default category and registered
		// default terms; the default link category it would delete, while
		// wp-admin keeps it (map_meta_cap: do_not_allow).
		$GLOBALS['ab_test_way']['taxonomies']['link_category'] = (object) array(
			'name' => 'link_category',
			'cap'  => (object) array( 'delete_terms' => 'manage_links' ),
		);
		$GLOBALS['ab_test_way']['terms'][6]              = (object) array( 'term_id' => 6, 'taxonomy' => 'link_category' );
		$GLOBALS['ab_test_options']['default_link_category'] = 6;
		$GLOBALS['ab_test_can']                          = static fn(): bool => true;
		$GLOBALS['ab_test_way']['term_deleted']          = true;
		$res = AB_MCP_Tools_Taxonomy_Comments::delete_term( array( 'taxonomy' => 'link_category', 'term_id' => 6 ) );
		self::assertSame( 'ab_mcp_delete_failed', $res->get_error_code() );
	}

	public function testATermWithheldFromTheAccountIsNotAssigned(): void {
		self::withhold( 'assign_term', 3 );
		$apply = new ReflectionMethod( AB_MCP_Tools_Content::class, 'apply_meta_and_terms' );

		// The list replaces the post's terms: with a refused term in it the
		// taxonomy keeps what it has.
		$apply->invoke( null, 10, array( 'terms' => array( 'category' => array( 3, 4 ) ) ) );
		self::assertSame( array(), $GLOBALS['ab_test_term_writes'] );

		// An id of no term is passed on: WordPress passes over it.
		$apply->invoke( null, 10, array( 'terms' => array( 'category' => array( 4, 99 ) ) ) );
		self::assertSame( array( array( 'set', 10, 'category', array( 4, 99 ) ) ), $GLOBALS['ab_test_term_writes'] );
	}

	public function testATermOfAnotherTaxonomyDoesNotBlockTheList(): void {
		// Tag 5 is withheld, but in a list of categories it names no
		// category: WordPress passes over it, as the REST API does.
		$GLOBALS['ab_test_way']['terms'][5] = (object) array( 'term_id' => 5, 'taxonomy' => 'post_tag' );
		self::withhold( 'assign_term', 5 );
		$apply = new ReflectionMethod( AB_MCP_Tools_Content::class, 'apply_meta_and_terms' );
		$apply->invoke( null, 10, array( 'terms' => array( 'category' => array( 4, 5 ) ) ) );
		self::assertSame( array( array( 'set', 10, 'category', array( 4, 5 ) ) ), $GLOBALS['ab_test_term_writes'] );
	}

	public function testACopyGetsTheTermsTheAccountMayAssign(): void {
		$GLOBALS['ab_test_way']['object_terms'][20]['category'] = array( 3, 4 );
		self::withhold( 'assign_term', 3 );
		$copy = new ReflectionMethod( AB_MCP_Tools_Content::class, 'copy_terms' );
		$copy->invoke( null, 20, 21, array( 'category' ) );
		self::assertSame( array( array( 'set', 21, 'category', array( 4 ) ) ), $GLOBALS['ab_test_term_writes'] );
	}

	/* --------------------------------------------------------------- comments */

	public function testACommentWithheldFromTheAccountIsNotModerated(): void {
		$GLOBALS['ab_test_comments'][5] = (object) array( 'comment_ID' => 5 );
		$GLOBALS['ab_test_comments'][6] = (object) array( 'comment_ID' => 6 );
		self::withhold( 'edit_comment', 5 );
		$res = AB_MCP_Tools_Taxonomy_Comments::moderate_comment( array( 'comment_id' => 5, 'status' => 'approve' ) );
		self::assertRefused( $res, 'cannot moderate this specific comment' );
		self::assertSame( array(), $GLOBALS['ab_test_moderated'] );

		$res = AB_MCP_Tools_Taxonomy_Comments::moderate_comment( array( 'comment_id' => 6, 'status' => 'approve' ) );
		self::assertTrue( $res['moderated'] );
		self::assertSame( array( array( 6, 'approve' ) ), $GLOBALS['ab_test_moderated'] );
	}

	public function testACommentWithheldFromTheAccountIsNotDeleted(): void {
		$GLOBALS['ab_test_comments'][5] = (object) array( 'comment_ID' => 5 );
		$GLOBALS['ab_test_comments'][6] = (object) array( 'comment_ID' => 6 );
		$GLOBALS['ab_test_way']['comment_deleted'] = true;
		self::withhold( 'edit_comment', 5 );
		$res = AB_MCP_Tools_Taxonomy_Comments::delete_comment( array( 'comment_id' => 5 ) );
		self::assertRefused( $res, 'cannot delete this specific comment' );

		$res = AB_MCP_Tools_Taxonomy_Comments::delete_comment( array( 'comment_id' => 6 ) );
		self::assertSame( array( 'deleted' => true, 'comment_id' => 6 ), $res );
	}

	public function testNoReplyUnderAPostTheAccountMayNotEdit(): void {
		$GLOBALS['ab_test_posts'][10] = (object) array( 'ID' => 10, 'post_type' => 'post', 'post_status' => 'publish' );
		$GLOBALS['ab_test_posts'][11] = (object) array( 'ID' => 11, 'post_type' => 'post', 'post_status' => 'publish' );
		$GLOBALS['ab_test_users'][2]  = (object) array( 'ID' => 2, 'display_name' => 'Anna', 'user_email' => 'anna@example.test' );
		$GLOBALS['ab_test_current_user'] = 2;
		self::withhold( 'edit_post', 10 );
		$res = AB_MCP_Tools_Taxonomy_Comments::reply_comment( array( 'post_id' => 10, 'content' => 'Danke!' ) );
		self::assertRefused( $res, 'cannot reply under this specific post' );
		self::assertSame( array(), $GLOBALS['ab_test_term_writes'] );

		$res = AB_MCP_Tools_Taxonomy_Comments::reply_comment( array( 'post_id' => 11, 'content' => 'Danke!' ) );
		self::assertSame( array( 'created' => true, 'comment_id' => 77 ), $res );
		self::assertSame( array( array( 'comment', 11 ) ), $GLOBALS['ab_test_term_writes'] );
	}
}
